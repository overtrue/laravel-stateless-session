<?php

namespace Tests;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Session;
use Orchestra\Testbench\TestCase;
use Overtrue\LaravelStatelessSession\Http\Middleware\SetSessionFromHeaders;
use PHPUnit\Framework\Attributes\DataProvider;

class SetSessionFromHeadersTest extends TestCase
{
    private string $sessionDirectory;

    protected function setUp(): void
    {
        $this->sessionDirectory = sys_get_temp_dir().'/stateless-session-'.bin2hex(random_bytes(8));
        mkdir($this->sessionDirectory);

        parent::setUp();

        $this->withoutExceptionHandling();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob($this->sessionDirectory.'/*') as $file) {
            unlink($file);
        }

        rmdir($this->sessionDirectory);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('session.driver', 'file');
        $app['config']->set('session.files', $this->sessionDirectory);
        $app['config']->set('session.cookie', 'stateless_test_session');
        $app['config']->set('session.lottery', [0, 100]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware([SetSessionFromHeaders::class, StartSession::class])->group(function ($router) {
            $router->get('/session', function (Request $request) {
                $request->session()->increment('visits');

                return response()->json([
                    'id' => $request->session()->getId(),
                    'visits' => $request->session()->get('visits'),
                ])->header('X-Application', 'preserved');
            });

            $router->get('/regenerate', function (Request $request) {
                $request->session()->regenerate(true);

                return response()->json([
                    'id' => $request->session()->getId(),
                    'visits' => $request->session()->get('visits'),
                ]);
            });

            $router->get('/invalidate', function (Request $request) {
                $request->session()->invalidate();

                return response()->json([
                    'id' => $request->session()->getId(),
                    'visits' => $request->session()->get('visits'),
                ]);
            });
        });

        $router->get('/without-session', fn () => response('no session'))
            ->middleware(SetSessionFromHeaders::class);
    }

    public function test_valid_header_loads_and_persists_session_across_fresh_requests(): void
    {
        $id = str_repeat('a', 40);

        $this->get('/session', ['X-Session' => $id])
            ->assertOk()
            ->assertHeader('x-session', $id)
            ->assertHeader('X-Application', 'preserved')
            ->assertJson(['id' => $id, 'visits' => 1]);

        $this->resetSessionManager();

        $this->get('/session', ['x-session' => $id])
            ->assertOk()
            ->assertHeader('x-session', $id)
            ->assertJson(['id' => $id, 'visits' => 2]);
    }

    public function test_configured_header_name_is_used_for_requests_and_responses(): void
    {
        $this->app['config']->set('session.header', 'X-Api-Session');
        $id = str_repeat('b', 40);

        $this->get('/session', ['x-api-session' => $id, 'x-session' => str_repeat('c', 40)])
            ->assertOk()
            ->assertHeader('X-Api-Session', $id)
            ->assertHeaderMissing('x-session')
            ->assertJsonPath('id', $id);
    }

    public function test_header_takes_precedence_over_an_existing_session_cookie(): void
    {
        $id = str_repeat('d', 40);

        $this->withUnencryptedCookie('stateless_test_session', str_repeat('e', 40))
            ->get('/session', ['x-session' => $id])
            ->assertOk()
            ->assertHeader('x-session', $id)
            ->assertJsonPath('id', $id);
    }

    public function test_response_contains_the_regenerated_id_and_preserves_session_data(): void
    {
        $oldId = str_repeat('f', 40);
        $this->get('/session', ['x-session' => $oldId])->assertJsonPath('visits', 1);
        $this->resetSessionManager();

        $response = $this->get('/regenerate', ['x-session' => $oldId])->assertOk();
        $newId = $response->json('id');

        $this->assertNotSame($oldId, $newId);
        $response->assertHeader('x-session', $newId)->assertJsonPath('visits', 1);
        $this->assertFileDoesNotExist($this->sessionDirectory.'/'.$oldId);
        $this->resetSessionManager();

        $this->get('/session', ['x-session' => $newId])->assertJsonPath('visits', 2);
        $this->resetSessionManager();
        $this->get('/session', ['x-session' => $oldId])->assertJsonPath('visits', 1);
    }

    public function test_invalidation_returns_new_id_and_drops_previous_session_data(): void
    {
        $oldId = str_repeat('g', 40);
        $this->get('/session', ['x-session' => $oldId])->assertJsonPath('visits', 1);
        $this->resetSessionManager();

        $response = $this->get('/invalidate', ['x-session' => $oldId])->assertOk();
        $newId = $response->json('id');

        $this->assertNotSame($oldId, $newId);
        $response->assertHeader('x-session', $newId)->assertJsonPath('visits', null);
        $this->assertFileDoesNotExist($this->sessionDirectory.'/'.$oldId);
        $this->resetSessionManager();

        $this->get('/session', ['x-session' => $newId])->assertJsonPath('visits', 1);
    }

    #[DataProvider('invalidSessionIds')]
    public function test_laravel_replaces_invalid_header_values_with_valid_session_ids(string $invalidId): void
    {
        $response = $this->get('/session', ['x-session' => $invalidId])->assertOk();
        $id = $response->json('id');

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{40}$/', $id);
        $this->assertNotSame($invalidId, $id);
        $response->assertHeader('x-session', $id);
    }

    public static function invalidSessionIds(): array
    {
        return [
            'too short' => ['invalid'],
            'too long' => [str_repeat('a', 41)],
            'non alphanumeric' => [str_repeat('-', 40)],
        ];
    }

    #[DataProvider('missingSessionIds')]
    public function test_first_request_exposes_a_session_id_that_can_be_reused(?string $header): void
    {
        $response = $this->get('/session', $header === null ? [] : ['x-session' => $header])->assertOk();
        $id = $response->json('id');

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{40}$/', $id);
        $response->assertHeader('x-session', $id)->assertJsonPath('visits', 1);
        $this->resetSessionManager();

        $this->get('/session', ['x-session' => $id])
            ->assertHeader('x-session', $id)
            ->assertJsonPath('visits', 2);
    }

    public static function missingSessionIds(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'undefined' => ['undefined'],
            'zero' => ['0'],
        ];
    }

    public function test_missing_header_preserves_existing_session_cookie(): void
    {
        $id = str_repeat('h', 40);

        $this->withUnencryptedCookie('stateless_test_session', $id)
            ->get('/session')
            ->assertOk()
            ->assertHeader('x-session', $id)
            ->assertJsonPath('id', $id);
    }

    public function test_custom_header_is_exposed_on_first_request(): void
    {
        $this->app['config']->set('session.header', 'X-Api-Session');

        $response = $this->get('/session')->assertOk();

        $response->assertHeader('X-Api-Session', $response->json('id'))
            ->assertHeaderMissing('x-session');
    }

    public function test_middleware_without_start_session_does_not_expose_an_unstarted_session(): void
    {
        $this->get('/without-session', ['x-session' => str_repeat('i', 40)])
            ->assertOk()
            ->assertSeeText('no session')
            ->assertHeaderMissing('x-session');
    }

    private function resetSessionManager(): void
    {
        // Recreate the store between requests, so persistence comes from the file
        // handler rather than the same in-memory session attributes.
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        Session::clearResolvedInstance('session');
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Session;
use Orchestra\Testbench\TestCase;
use Overtrue\LaravelStatelessSession\Http\Middleware\SetSessionFromHeaders;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

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

        $configuration = new Middleware();
        $configuration->api(prepend: [SetSessionFromHeaders::class, StartSession::class]);
        $router->middlewareGroup('configured-api', $configuration->getMiddlewareGroups()['api']);

        $router->middleware('configured-api')->group(function ($router) {
            $router->get('/configured-api', function (Request $request) {
                $request->session()->increment('visits');

                return response()->json([
                    'id' => $request->session()->getId(),
                    'visits' => $request->session()->get('visits'),
                ])->header('x-session', 'misleading-downstream-value');
            });

            $router->get('/error', function (Request $request) {
                $request->session()->put('error_was_seen', true);
                abort(422, 'Validation error');
            });

            $router->get('/error-state', fn (Request $request) => response()->json([
                'seen' => $request->session()->get('error_was_seen'),
            ]));
        });
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

    public function test_documented_registration_persists_and_returns_final_header(): void
    {
        $id = str_repeat('R', 40);
        $this->get('/configured-api', ['x-session' => $id])
            ->assertOk()
            ->assertHeader('x-session', $id)
            ->assertJson(['id' => $id, 'visits' => 1]);
        $this->resetSessionManager();
        $this->get('/configured-api', ['x-session' => $id])
            ->assertOk()
            ->assertHeader('x-session', $id)
            ->assertJsonPath('visits', 2);
    }

    public function test_rendered_error_keeps_session_header_and_persists_data(): void
    {
        $this->withExceptionHandling();
        $response = $this->getJson('/error')->assertStatus(422)->assertHeader('x-session');
        $id = $response->headers->get('x-session');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{40}$/', $id);
        $this->resetSessionManager();
        $this->get('/error-state', ['x-session' => $id])->assertOk()->assertJsonPath('seen', true);
    }

    public function test_no_stale_id_is_returned_after_a_session_request(): void
    {
        $id = str_repeat('S', 40);
        $this->get('/session', ['x-session' => $id])->assertOk()->assertHeader('x-session', $id);
        $this->get('/without-session', ['x-session' => $id])->assertOk()->assertHeaderMissing('x-session');
        $this->get('/without-session')->assertOk()->assertHeaderMissing('x-session');
    }

    public function test_ignored_headers_preserve_existing_cookie(): void
    {
        $id = str_repeat('T', 40);

        foreach (['', 'undefined', '0'] as $index => $ignored) {
            $this->withUnencryptedCookie('stateless_test_session', $id)
                ->get('/session', ['x-session' => $ignored])
                ->assertOk()
                ->assertHeader('x-session', $id)
                ->assertJsonPath('visits', $index + 1);
            $this->resetSessionManager();
        }
    }

    public function test_callback_runs_once_and_preserves_response_identity(): void
    {
        $request = Request::create('/direct');
        $id = str_repeat('U', 40);
        $request->headers->set('x-session', $id);
        $response = response('hello', 201)->header('x-application', 'preserved');
        $calls = 0;
        $actual = (new SetSessionFromHeaders())->handle($request, function ($received) use ($request, $response, $id, &$calls) {
            $calls++;
            $this->assertSame($request, $received);
            $this->assertSame($id, $received->cookies->get('stateless_test_session'));

            return $response;
        });
        $this->assertSame(1, $calls);
        $this->assertSame($response, $actual);
        $this->assertFalse($actual->headers->has('x-session'));
    }

    public function test_callback_exceptions_propagate_unchanged(): void
    {
        $request = Request::create('/direct');
        $expected = new RuntimeException('deliberate callback error');

        try {
            (new SetSessionFromHeaders())->handle($request, function () use ($expected) {
                throw $expected;
            });
            $this->fail('Callback exception was swallowed.');
        } catch (RuntimeException $actual) {
            $this->assertSame($expected, $actual);
        }
    }

    public function test_response_uses_attached_request_store_not_unrelated_manager_store(): void
    {
        $request = Request::create('/direct');
        $id = str_repeat('V', 40);
        $store = new Store('separate_store', new ArraySessionHandler(120), $id);
        $store->start();
        $request->setLaravelSession($store);
        $this->assertNotSame($id, Session::getId());
        $response = (new SetSessionFromHeaders())->handle($request, fn () => response('attached'));
        $this->assertSame($id, $response->headers->get('x-session'));
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

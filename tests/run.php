<?php
/** Integration suite: real Evolution 3.1.31 models, Illuminate 8, SQLite. */
// Evolution 3.1.31 also excludes vendor PHP 8.4 deprecations.
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../vendor/autoload.php';

use EvolutionCMS\AiAssistant\Services\{AiService, CheckpointService, Installer, ModelCatalog, ResourceService, TemplateService, TvService};
use EvolutionCMS\AiAssistant\Support\ManagerSecurity;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\{Facade, Http};
use Illuminate\Http\Client\Factory;

$source = getenv('EVO_SOURCE');
if (!$source || !is_file($source . '/core/src/Models/SiteContent.php')) {
    fwrite(STDERR, "Set EVO_SOURCE to a checkout of evocms-community/evolution 3.1.31.\n");
    exit(1);
}
spl_autoload_register(function ($class) use ($source) {
    $prefix = 'EvolutionCMS\\';
    if (str_starts_with($class, $prefix)) {
        $path = $source . '/core/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require_once $path;
    }
});
$testRoot = sys_get_temp_dir() . '/evo-ai-tests-' . getmypid();
mkdir($testRoot . '/views', 0755, true);
mkdir($testRoot . '/storage', 0755, true);
define('MODX_BASE_PATH', $testRoot . '/');
define('MODX_SITE_URL', 'https://example.test/subdir/');
define('IN_MANAGER_MODE', true);
function public_path($path = '') { return MODX_BASE_PATH . $path; }
function resource_path($path = '') { return MODX_BASE_PATH . $path; }
function storage_path($path = '') { return MODX_BASE_PATH . 'storage/' . $path; }
function env($key, $default = null) { return $default; }
function app($key = null) { return $key === null ? Container::getInstance() : Container::getInstance()->make($key); }
function config($key = null, $default = null) { return app('config')->get($key, $default); }
function evo() { return $GLOBALS['evoFixture']; }
function evolutionCMS() { return evo(); }
function response($content = null) {
    if ($content !== null) return new Illuminate\Http\Response($content);
    return new class { public function json($data, $status = 200) { return new Illuminate\Http\JsonResponse($data, $status); } };
}
$GLOBALS['evoFixture'] = new class {
    public int $clears = 0;
    public array $settings = [];
    public function getLoginUserID($context = 'mgr') { return 1; }
    public function getConfig($key, $default = null) { return $this->settings[$key] ?? $default; }
    public function clearCache($type) { $this->clears++; }
    public function hasPermission($permission) { return true; }
    public function normalizeFormat() { return 'Y-m-d H:i:s'; }
};
$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new Illuminate\Config\Repository());
$app->instance(Factory::class, new Factory());
$app->instance('files', new Illuminate\Filesystem\Filesystem());
$app->instance('log', new Psr\Log\NullLogger());
$db = new Capsule($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'evo_']);
$db->setEventDispatcher(new Illuminate\Events\Dispatcher($app));
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$app->bind('db.schema', fn() => $db->schema());
$_SESSION = ['mgrValidated' => 1, 'mgrInternalKey' => 1, 'mgrRole' => 1];
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS $message\n";
}
class_alias(Illuminate\Support\Facades\Schema::class, 'Schema');
class_alias(Illuminate\Support\Facades\DB::class, 'DB');
// Use upstream table definitions, including real defaults, primary keys,
// unique indexes and closure-table layout, rather than simplified fixtures.
foreach (['system_settings', 'site_plugins', 'site_modules', 'system_eventnames',
    'site_plugin_events', 'site_templates', 'site_tmplvars', 'site_tmplvar_templates',
    'site_tmplvar_contentvalues', 'site_content'] as $table) {
    $migration = require $source . '/install/stubs/migrations/2018_06_29_182342_create_' . $table . '_table.php';
    $migration->up();
}
(require $source . '/install/stubs/migrations/2020_09_12_110820_create_site_content_closure.php')->up();
Capsule::table('system_eventnames')->insert(['name' => 'OnManagerMainFrameHeaderHTMLBlock']);
(new Installer())->install();
Capsule::table('system_settings')->where('setting_name', 'ai_assistant_api_key')->update(['setting_value' => 'secret-fixture']);
(new Installer())->install();
check(Capsule::table('site_plugins')->count() === 1 && Capsule::table('site_modules')->count() === 1, 'installer is idempotent');
check(Capsule::table('system_settings')->where('setting_name', 'ai_assistant_api_key')->value('setting_value') === 'secret-fixture', 'upgrade preserves API key');
check(Capsule::schema()->hasTable('ai_assistant_checkpoints'), 'installer creates model checkpoint table');
check(is_file(public_path('assets/ai-assistant/js/panel.js')), 'installer publishes assets');
$cp = new CheckpointService();
$templates = new TemplateService($cp);
$tvs = new TvService($cp);
$resources = new ResourceService($cp);
$template = $templates->create(['templatename' => 'Product']);
$tv = $tvs->create(['name' => 'price', 'templates' => [$template->id]]);
check($template->fresh()->tvs->count() === 1, 'TV creation with template bindings');
$parent = $resources->create(['pagetitle' => 'Products', 'isfolder' => 1]);
$child = $resources->create(['pagetitle' => 'Product 1', 'parent' => $parent->id, 'template' => $template->id]);
check($child->parent === $parent->id, 'creates nested resources using Evolution models');
$resources->update($child->id, ['pagetitle' => 'Changed']);
$checkpoint = EvolutionCMS\AiAssistant\Models\Checkpoint::latest('id')->first();
check($cp->rollback($checkpoint) && $resources->get($child->id)['pagetitle'] === 'Product 1', 'resource checkpoint rollback');
$resources->updateTv($child->id, 'price', '0');
check($resources->getTvValues($child->id)['price']['value'] === '0', 'TV accepts zero value');
$checkpoint = EvolutionCMS\AiAssistant\Models\Checkpoint::latest('id')->first();
check($cp->rollback($checkpoint) && Capsule::table('site_tmplvar_contentvalues')->count() === 0, 'TV rollback restores absence of override');
check($resources->publish($child->id)->published === 1 && $resources->unpublish($child->id)->published === 0, 'publish and unpublish');
file_put_contents(MODX_BASE_PATH . 'views/product.blade.php', 'Before');
$template->templatealias = 'product'; $template->save();
check($templates->updateBladeTemplate($template->id, 'After'), 'Blade update');
$checkpoint = EvolutionCMS\AiAssistant\Models\Checkpoint::latest('id')->first();
check($cp->rollback($checkpoint) && file_get_contents(MODX_BASE_PATH . 'views/product.blade.php') === 'Before', 'Blade rollback restores file');
$reflection = new ReflectionMethod(TemplateService::class, 'resolveViewPath'); $reflection->setAccessible(true);
check($reflection->invoke($templates, '../outside') === null, 'rejects template traversal');
check(!ManagerSecurity::validToken('wrong') && ManagerSecurity::validToken(ManagerSecurity::token()), 'CSRF tokens');
$middleware = new EvolutionCMS\AiAssistant\Http\Middleware\AiAssistantAuth();
$next = fn() => response('ok');
$request = Illuminate\Http\Request::create('/ai-assistant/api/chat', 'POST');
check($middleware->handle($request, $next)->getStatusCode() === 403, 'rejects mutation without CSRF');
$request->headers->set('X-AI-CSRF-Token', ManagerSecurity::token());
check($middleware->handle($request, $next)->getStatusCode() === 200, 'accepts admin with CSRF');
$_SESSION['mgrRole'] = 2;
check($middleware->handle($request, $next)->getStatusCode() === 403, 'rejects non-admin manager');
$_SESSION['mgrRole'] = 1;
$catalog = ModelCatalog::normalize([
 ['id' => 'valid/model', 'supported_parameters' => ['tools'], 'architecture' => ['output_modalities' => ['text']], 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002']],
 ['id' => 'no/tools', 'supported_parameters' => []],
]);
check(count($catalog) === 1 && $catalog[0]['prompt_per_million'] === 1.0, 'catalog filters tools and converts prices');
$config = require __DIR__ . '/../config/ai-assistant.php';
$config['providers']['openai']['api_key'] = 'fixture';
$config['providers']['anthropic']['api_key'] = 'fixture';
$tool = ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_resource', 'arguments' => '{"id":1}']];
$sequence = Http::sequence()->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [$tool], 'reasoning_details' => [['type' => 'reasoning.text', 'text' => 'fixture']]]]]])->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Done']]]]);
Http::fake(['*' => $sequence]);
$ai = new AiService($config); $ai->setToolExecutor(fn() => ['success' => true, 'data' => ['id' => 1]]);
$result = $ai->chat('Get page');
check($result['success'] && count($result['actions']) === 1, 'OpenRouter multi-turn tool loop');
check(count(Http::recorded(fn($r) => isset($r['messages'][2]['reasoning_details']) && ($r['messages'][3]['tool_call_id'] ?? '') === 'call_1')) === 1, 'OpenRouter request metadata');
$config['provider'] = 'anthropic';
Http::swap(new Factory());
Http::fake(['*' => Http::sequence()->push(['content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_resource', 'input' => ['id' => 1]]]])->push(['content' => [['type' => 'text', 'text' => 'Done']]])]);
$ai = new AiService($config); $ai->setToolExecutor(fn() => ['success' => true]);
check($ai->chat('Get page')['success'], 'Anthropic multi-turn tool loop');
check(count(Http::recorded(fn($r) => ($r['messages'][2]['role'] ?? '') === 'user' && ($r['messages'][2]['content'][0]['type'] ?? '') === 'tool_result')) === 1, 'Anthropic request blocks');
$config['provider'] = 'openai'; $config['max_iterations'] = 1;
Http::swap(new Factory());
Http::fake(['*' => Http::response(['choices' => [['message' => ['tool_calls' => [$tool]]]]])]);
$ai = new AiService($config); $ai->setToolExecutor(fn() => ['success' => true]);
$result = $ai->chat('Loop');
check(!$result['success'] && count($result['actions']) === 1, 'iteration cap reports partial work');
$tool['function']['arguments'] = '{broken';
Http::swap(new Factory()); Http::fake(['*' => Http::response(['choices' => [['message' => ['tool_calls' => [$tool]]]]])]);
$called = false; $ai = new AiService($config); $ai->setToolExecutor(function () use (&$called) { $called = true; return []; });
check(!$ai->chat('Broken')['success'] && !$called, 'invalid tool JSON never executes actions');
Http::swap(new Factory()); Http::fake(['*' => Http::response(['error' => ['message' => 'provider failed']])]);
check(!(new AiService($config))->chat('Error')['success'], 'HTTP 200 provider error is not success');
$controller = new EvolutionCMS\AiAssistant\Controllers\ApiController();
check($controller->status()->getStatusCode() === 200, 'status endpoint initializes its services');
check($controller->listTemplates()->getStatusCode() === 200, 'direct endpoint initializes its services');
check(is_subclass_of(EvolutionCMS\AiAssistant\AiAssistantServiceProvider::class, EvolutionCMS\ServiceProvider::class), 'provider loads with upstream base class');
// Render the actual Blade panel through Illuminate's view engine.
$files = $app['files'];
$compiler = new Illuminate\View\Compilers\BladeCompiler($files, $testRoot . '/storage');
$resolver = new Illuminate\View\Engines\EngineResolver();
$resolver->register('blade', fn() => new Illuminate\View\Engines\CompilerEngine($compiler, $files));
$finder = new Illuminate\View\FileViewFinder($files, [__DIR__ . '/../views']);
$view = new Illuminate\View\Factory($resolver, $finder, new Illuminate\Events\Dispatcher($app));
$view->setContainer($app); $app->instance('view', $view);
$view->addNamespace('ai-assistant', __DIR__ . '/../views');
function view($name, $data = []) { return app('view')->make($name, $data); }
function url($path) { return rtrim(MODX_SITE_URL, '/') . '/' . ltrim($path, '/'); }
function config_path($path) { return MODX_BASE_PATH . 'core/custom/config/' . $path; }
$panel = (new EvolutionCMS\AiAssistant\Controllers\PanelController())->index()->getContent();
check(str_contains($panel, 'ai-messages') && str_contains($panel, 'csrfToken') && str_contains($panel, '/subdir/assets/ai-assistant/js/panel.js'), 'renders real Blade panel with CSRF and subdirectory URLs');
// Register all package routes and boot against the real Evolution provider base.
$router = new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher($app), $app);
$app->instance('router', $router);
$app->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
(new EvolutionCMS\AiAssistant\AiAssistantServiceProvider($app))->boot();
check(count($router->getRoutes()) >= 30, 'provider boots and registers full API routes');
check(EvolutionCMS\AiAssistant\Support\ActionPolicy::allowed('update_resource', ['edit_resources' => false]) === false, 'disabled action policy');
Http::swap(new Factory());
Http::fake(['*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Done']]]])]);
$config['actions']['search_resources'] = false;
(new AiService($config))->chat('Hello');
check(count(Http::recorded(fn($r) => !in_array('get_resource', array_column(array_column($r['tools'], 'function'), 'name'), true))) === 1, 'disabled tools omitted from provider request');
echo "OK: $checks checks, PHP " . PHP_VERSION . "\n";
// Remove only the isolated test directory.
$app['files']->deleteDirectory($testRoot);

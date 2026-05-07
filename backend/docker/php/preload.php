<?php
/**
 * PHP Preload Script for Maximum Performance
 * Pre-compiles and keeps critical classes in memory
 * 
 * Configure in php.ini:
 * opcache.preload=/var/www/html/docker/php/preload.php
 * opcache.preload_user=www-data
 */

// Only preload in production
if (getenv('APP_ENV') !== 'production') {
    return;
}

$paths = [
    // Laravel Framework Core (most frequently used classes)
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Routing/Router.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Routing/Route.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Http/Request.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Http/Response.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Http/JsonResponse.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Database/DatabaseManager.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Support/Collection.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Cache/Repository.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Cache/RedisStore.php',
    
    // Authentication & Authorization
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Auth/AuthManager.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php',
    '/var/www/html/vendor/laravel/sanctum/src/PersonalAccessToken.php',
    
    // Validation & Middleware
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Validation/Validator.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php',
    
    // Events & Queue
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Queue/QueueManager.php',
    '/var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php',
];

// Preload application classes
$appPaths = glob('/var/www/html/app/{Models,Services,Http/Controllers,Http/Middleware}/*.php', GLOB_BRACE);
$appPaths = array_merge($appPaths, glob('/var/www/html/app/{Models,Services}/**/*.php', GLOB_BRACE));

$allPaths = array_merge($paths, $appPaths);

$preloaded = 0;
$failed = 0;

foreach ($allPaths as $file) {
    if (!file_exists($file)) {
        continue;
    }
    
    try {
        opcache_compile_file($file);
        $preloaded++;
    } catch (Throwable $e) {
        // Silently skip files that can't be preloaded
        $failed++;
    }
}

// Log preload statistics
error_log("PHP Preload completed: {$preloaded} files preloaded, {$failed} failed");

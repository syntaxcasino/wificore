<?php return array (
  'evilfreelancer/routeros-api-php' => 
  array (
    'aliases' => 
    array (
      'RouterOS' => 'RouterOS\\Laravel\\Facade',
    ),
    'providers' => 
    array (
      0 => 'RouterOS\\Laravel\\ServiceProvider',
    ),
  ),
  'laravel/reverb' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Reverb\\ApplicationManagerServiceProvider',
      1 => 'Laravel\\Reverb\\ReverbServiceProvider',
    ),
  ),
  'laravel/sanctum' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Sanctum\\SanctumServiceProvider',
    ),
  ),
  'laravel/tinker' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Tinker\\TinkerServiceProvider',
    ),
  ),
  'mailersend/laravel-driver' => 
  array (
    'aliases' => 
    array (
      'LaravelDriver' => 'MailerSend\\LaravelDriver\\LaravelDriverFacade',
    ),
    'providers' => 
    array (
      0 => 'MailerSend\\LaravelDriver\\LaravelDriverServiceProvider',
    ),
  ),
  'nesbot/carbon' => 
  array (
    'providers' => 
    array (
      0 => 'Carbon\\Laravel\\ServiceProvider',
    ),
  ),
  'nunomaduro/termwind' => 
  array (
    'providers' => 
    array (
      0 => 'Termwind\\Laravel\\TermwindServiceProvider',
    ),
  ),
  'pestphp/pest-plugin-laravel' => 
  array (
    'providers' => 
    array (
      0 => 'Pest\\Laravel\\PestServiceProvider',
    ),
  ),
);
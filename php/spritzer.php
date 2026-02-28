<?php

declare(strict_types=1);
spl_autoload_register( function ( string $className ) : void {
  $paths = ['system_', 'modul_'];
  $className = strtolower( $className );
  foreach( $paths as $path ){
    $path = __DIR__ . '/' . $path . $className . '.php';
    if( file_exists( $path )){
      require_once $path;
      if( method_exists( $className, 'init' )) $className::init();
      return;
    }
  }
} );
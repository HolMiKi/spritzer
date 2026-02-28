<?php

declare(strict_types=1);

/** Spritzer autoloader
 *
 * Verziózott osztálybetöltés PHP 8.4+ kompatibilis
 *
 * @author Tánczos Róbert <tanczos.robert@gmail.com>
 * @copyright Copyright (c) 2025, Tánczos Róbert
 *
 * @version 1.0.0
 * @since 1.0.0 2025.10.11 PHP 8.4 migrálás, strict types, dokumentáció
 */

if( !defined( 'CLASSVERSIONS' )) define( 'CLASSVERSIONS', [] );

spl_autoload_register( function ( string $className ) : void {
  $className = strtolower( $className );
  $version = isset( CLASSVERSIONS[$className] ) ? '_' . CLASSVERSIONS[$className] : '';
  $paths = ['system_', 'modul_'];
  
  foreach( $paths as $path ){
    $path = __DIR__ . '/' . $path . $className . $version . '.php';
    if( file_exists( $path )){
      require_once $path;
      if( method_exists( $className, 'init' )) $className::init();
      return;
    }
  }
} );
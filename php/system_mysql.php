<?php

declare( strict_types = 1 );
define( 'OBJECT_TYPE', true );

class Mysql{

  private static string $configFile = '/config/config_mysql.php';
  private static array $config = [];
  private static ?mysqli $contact = null;
  private static bool $loggedQuery = false;
  private static string $loggedTables = '';

  public static int $piece = 0;

  private static function _connect(): void {
    if( !is_null( self::$contact )) return;
    mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
    if( empty( self::$config )){
      if( !file_exists( $_SERVER['DOCUMENT_ROOT'] . self::$configFile )){
        user_error( 'MySQL config file missing: ' . self::$configFile, E_USER_ERROR );
        exit( 'Database config missing!' );
      }
      require_once $_SERVER['DOCUMENT_ROOT'] . self::$configFile;
      if( !defined( 'DATABASE' )){
        user_error( 'MySQL config missing: DATABASE', E_USER_ERROR );
        exit( 'Database config missing!' );
      }
      self::$config = DATABASE;
    }

    self::$contact = new mysqli(
      self::$config['servername'],
      self::$config['username'],
      self::$config['pwd'],
      self::$config['dbname'],
      (int) self::$config['port']
    );
    self::$contact->set_charset( self::$config['charset'] );
  }

  private static function _log( string $sql, array $data, string $types ): void {
    $logData = [
      'sql' => $sql,
      'data' => $data,
      'types' => $types,
      'time' => date('Y-m-d H:i:s')
    ];
    user_error( 'DB QUERY: ' . json_encode( $logData ));
  }

  private static function _buildWhereClause( array|int|string $where ): array {
    $data = [];
    $types = '';
    $sql = '';

    if( is_int( $where ) or !empty( $where ))
      match( true ){
        is_array( $where ) => ( function() use( &$sql, &$data, &$types, $where ){
          $sql = ' WHERE '.$where[0];
          if( is_array( $where[1] ))
            foreach( $where[1] as $key => $value ){
              $data[] = $value;
              $types .= $where[2][$key] ?? 's';
            }
          else{
            if( strpos( ' '.$where[0], '?' ) === false ) $sql .= '=?';
            $data[] = $where[1];
            $types = $where[2] ?? 's';
          }
        } )(),
        is_string( $where ) => $sql = ' WHERE '.$where,
        is_int( $where ) => ( function() use( &$sql, &$data, &$types, $where ){
          $sql = ' WHERE id=?';
          $data[] = $where;
          $types = 'i';
        } )()
      };

    return [
      'sql' => $sql,
      'data' => $data,
      'types' => $types
    ];
  }

  public static function set_configFile( string|array $config ): void {
    if( is_array( $config ))
      self::$config = $config;
    else
      self::$configFile = $config;
  }

  public static function set_loggedQuery( bool $logged = false ): void {
    self::$loggedQuery = $logged;
  }

  public static function set_loggedTable( string $tables = '' ): void {
    self::$loggedTables = $tables;
  }

  protected final static function query( string $sql, mixed $data = [], string $types = '' ): \mysqli_stmt|\mysqli_result|bool {
    self::_connect();

    if(
      self::$loggedQuery and (
        self::$loggedTables == '' or
        array_reduce( explode( ',', self::$loggedTables ), fn( $carry, $table ) => $carry or str_contains( $sql, $table ), false )
      )
    )
      self::_log( $sql, $data, $types );

    if( empty( $data ))
      return self::$contact->query( $sql );

    if( !is_array( $data ))
      $data = [$data];

    $stmt = self::$contact->prepare( $sql );
    if( !is_object( $stmt )){
      user_error( 'Prepare failed: ' . self::$contact->error, E_USER_ERROR );
      exit( 'Prepare failed!' );
    }
    if( empty( $types ))
      $types = str_repeat( 's', count( $data ));

    if( count( $data ) > strlen( $types ))
      $types .= str_repeat( 's', count( $data ) - strlen( $types ));

    if( !$stmt->bind_param( $types, ...$data )){
      user_error( 'Bind param failed: ' . $stmt->error, E_USER_ERROR );
      exit( 'Bind param failed!' );
    }

    if( !$stmt->execute()){
      user_error( 'Execute failed: ' . $stmt->error, E_USER_ERROR );
      exit( 'Execute failed!' );
    }

    return $stmt;
  }

  protected static function save( string $table, array $datas, array|int|string|false $where = false ): int {
    if( empty( $datas )) return 0;

    $sql = ( empty( $where )? 'INSERT INTO ' : 'UPDATE ' ) . $table . ' SET ';
    $fields = $values = [];
    $types = '';

    if( isset( $datas[0] ) and is_array( $datas[0] ))
      foreach( $datas as $item ){
        if( !isset( $item[0], $item[1] )) continue;
        $fields[] = $item[0] . '=?';
        $values[] = $item[1];
        $types .= $item[2] ?? 's';
      }
    elseif( array_values( $datas ) !== $datas )
      foreach( $datas as $key => $value ){
        $fields[] = $key . '=?';
        $values[] = $value;
        $types .= is_int( $value ) ? 'i' : ( is_float( $value ) ? 'd' : 's' );
      }
    else{
      if( !isset( $datas[0], $datas[1] )) return 0;
      $fields[] = $datas[0] . '=?';
      $values[] = $datas[1];
      $types .= $datas[2] ?? 's';
    }

    $sql .= implode( ', ', $fields );

    if( !empty( $where )){
      $whereData = self::_buildWhereClause( $where );
      $sql .= $whereData['sql'];
      $values = array_merge( $values, $whereData['data'] );
      $types .= $whereData['types'];
    }

    if( !$res = self::query( $sql, $values, $types )) return 0;
    return empty( $where ) ? $res->insert_id : $res->affected_rows;
  }

  protected static function get( string $table, array|int|string $where, bool $object = false, string $columns = '*' ): array|object|false {
    $sql = 'SELECT '.$columns.' FROM '.$table;
    if( empty( $where )) return false;
    $whereData = self::_buildWhereClause( $where );
    $sql .= $whereData['sql'] . ' LIMIT 1';
    if( !$res = self::query( $sql, $whereData['data'], $whereData['types'] )) return false;
    if( !$res = $res instanceof \mysqli_stmt ? $res->get_result() : $res ) return false;
    return ( $object ? $res->fetch_object() : $res->fetch_assoc()) ?? false;
  }

  protected static function list( string $table, array|string|false $where = false, string|false $order = false, int $limit = 0, int $offset = 0, string $columns = '*' ): array|false {
    $data = [];
    $types = '';
    $sql = 'SELECT '.$columns.' FROM '.$table;

    if( !empty( $where )){
      $whereData = self::_buildWhereClause( $where );
      $sql .= $whereData['sql'];
      $data = $whereData['data'];
      $types = $whereData['types'];
    }

    self::$piece = 0;
    if( $limit )
      if( $res = self::query( $sql, $data, $types ))
        if( $res = $res instanceof \mysqli_stmt ? $res->get_result() : $res )
          self::$piece = $res->num_rows ?? 0;

    if( $order )
      $sql .= ' ORDER BY '.$order;

    if( $limit )
      $sql .= $offset ? ' LIMIT ' . $offset . ',' . $limit : ' LIMIT ' . $limit;

    if( !$res = self::query( $sql, $data, $types )) return false;
    if( !$res = $res instanceof \mysqli_stmt ? $res->get_result() : $res ) return false;
    $rows = $res->fetch_all( MYSQLI_ASSOC );
    return empty( $rows ) ? false : $rows;
  }

  protected static function del( string $table, array|int|string $where, int|string|null $status = null ): int {
    if( empty( $where )) return 0;

    $sql = $status === null
      ? 'DELETE FROM ' . $table
      : 'UPDATE ' . $table . ' SET signs=CONCAT("' . $status . '", IF(LENGTH(signs) > 1, MID(signs, 2), ""))';

    $data = [];
    $types = '';

    if( $where !== 'ALL' ){
      $whereData = self::_buildWhereClause( $where );
      $sql .= $whereData['sql'];
      $data = $whereData['data'];
      $types = $whereData['types'];
    }

    return self::query( $sql, $data, $types )?->affected_rows ?? 0;
  }

  protected static function get_piece( string $table, array|int|string|false $where = false ): int {
    $sql = "SELECT * FROM {$table}";

    $data = [];
    $types = '';

    if( $where ){
      $whereData = self::_buildWhereClause( $where );
      $sql .= $whereData['sql'];
      $data = $whereData['data'];
      $types = $whereData['types'];
    }

    if( !$res = self::query( $sql, $data, $types )) return 0;
    $res = $res instanceof \mysqli_stmt ? $res->get_result() : $res;
    return $res?->num_rows ?? 0;
  }

  protected static function get_columnName( string $table ): array|false {
    if( !$res = self::query( 'SHOW COLUMNS FROM '.$table )) return false;
    if( !$res = $res instanceof \mysqli_stmt ? $res->get_result() : $res ) return false;
    return array_column($res->fetch_all(MYSQLI_ASSOC), 'Field') ?: false;
  }
}
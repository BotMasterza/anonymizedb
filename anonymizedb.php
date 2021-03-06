<?php
/**
 * @author Alkante https://www.alkante.com/
 * @license CeCILL-C
 * @version 20180926
 */

if(!is_file('config.php')){
  echo "Please copy config.php.dist to config.php and edit it.\n";
  exit();
}

$GLOBALS['dico']=[];
include 'config.php';

$elms = scandir("./functions/");
unset($elms[0]);// .
unset($elms[1]);// ..
if(!empty($elms)){
  foreach ($elms as $file) {
    if(substr($file, -4) == ".php"){
      include "./functions/$file";
    }
  }
}

if (php_sapi_name() == "cli") {
  if(!count($_SERVER['argv']) > 1 || empty($_SERVER['argv'][1])){
    showHelp();
    exit();
  }
  try {
    if($_SERVER['argv'][1] == "help"){
      if(empty($_SERVER['argv'][2])){
        showHelp();
      }else{
        showHelpFunction($_SERVER['argv'][2]);
      }
    }elseif($_SERVER['argv'][1] == "list"){
      listAllFunction();
    }else{
      run($_SERVER['argv'][1], $_SERVER['argv'][2]);
    }
  } catch (Exception $e) {
    echo $e->getMessage()."\n";
  }
} else {
    echo "Sorry cli only<br>\n";
    echo "TODO web interface\n";
}

/**
 * Show help
 */
function showHelp(){
  echo "Usage\n\n";
  echo "Update database with random data :\n";
  echo "php ".$_SERVER['argv'][0]." ./json/databases/<jsonFileName> ./json/tables/<jsonFileName>\n";
  echo "Ex : php ".$_SERVER['argv'][0]." ./json/databases/mydatabase.json ./json/tables/mytable.json\n";
  echo "\nList available functions in json :\n";
  echo "php ".$_SERVER['argv'][0]." list\n";
  echo "\nHelp for a function :\n";
  echo "php ".$_SERVER['argv'][0]." help <functionName>\n";
  echo "Ex : php ".$_SERVER['argv'][0]." help name\n";
}

/**
 * Show help for a function
 * @param string $functionName Name of the function
 */
function showHelpFunction(string $functionName){
  if(function_exists(FUNCTION_PREFIX.$functionName)){
    $functionName = FUNCTION_PREFIX.$functionName;
    execHelpFunction($functionName);
  }else{
    throw new Exception("This function doesn't exists!");
  }
}

/**
 * Print help for a function
 * @param string $functionName Name of the function
 */
function execHelpFunction(string $functionName){
  $help = $functionName("", [],['showHelp'=>true]);
  if(!empty($help) && is_array($help)){
    foreach ($help as $helpLine => $comment) {
      echo ucfirst($helpLine)." :\n";
      if(is_array($comment)){
        foreach ($comment as $paramName => $comment) {
          echo "- $paramName :\n\t$comment\n";
        }
      }else{
        echo "\t$comment\n";
      }
    }
  }else{
    echo "$help\n";
  }
}

/**
 * Print the list of available functions.
 */
function listAllFunction(){
  $prefixLen = strlen(FUNCTION_PREFIX);
  $function_prefix = strtolower(FUNCTION_PREFIX);
  foreach (get_defined_functions()['user'] as $functionName) {
    if(substr($functionName, 0, $prefixLen) == $function_prefix){
      $help = $functionName("", [],['showHelp'=>true]);
      $h = "";
      $p = [];
      if(is_array($help)){
        if(!empty($help['return'])){
          $h = $help['return'];
        }
        if(!empty($help['param'])){
          foreach ($help['param'] as $param => $value) {
            $p[] = $param;
          }
        }
      }else{
        $h = $help;
      }
      if(!empty($p)){
        $p = " Param: (".implode(",", $p).")";
      }else{
        $p = "";
      }
      printf("\r%-15s", substr($functionName, $prefixLen));
      echo "\t: $h$p\n";
    }
  }
}

/**
 * Run
 * @param string $jsonDatabaseFileName Json file with database access informations
 * @param string $jsonTableFileName Json file with table structure
 */
function run(string $jsonDatabaseFileName, string $jsonTableFileName){
  if(!is_file($jsonDatabaseFileName)){
    throw new Exception("Not database json file!");
  }

  if(!is_file($jsonTableFileName)){
    throw new Exception("Not table json file!");
  }

  $json = [
    'database' => [],
    'tables' => []
  ];

  $json['database'] = @json_decode(@file_get_contents($jsonDatabaseFileName), true);
  if(empty($json['database'])){
    throw new Exception("Database json file : parsing error!\n".json_last_error_msg ());
  }

  $json['tables'] = @json_decode(@file_get_contents($jsonTableFileName), true);
  if(empty($json['tables'])){
    throw new Exception("Table json file : parsing error!\n".json_last_error_msg ());
  }

  if(!empty($json['database'])){
    $dbh = bddConnect($json['database']);
  }else{
    throw new Exception("No database in json!");
  }

  if(empty($json['tables'])){
    throw new Exception("No tables in json!");
  }
  foreach ($json['tables'] as $tableName => $tableData) {
    if(empty($tableData['id'])){
      throw new Exception("In json, no id found for $tables.$tableName!");
    }
    if(empty($tableData['fields'])){
      throw new Exception("In json, no fields found for $tables.$tableName!");
    }
    if(!isset($tableData['skipline'])){
      $tableData['skipline'] = [];
    }
    $schema = "";
    if(!empty($tableData['schema'])){
      $dbh->exec("SET search_path TO ".$tableData['schema']);//FIXME escape char
    }
    checkFields($tableData['fields'], $tableName);
    $countData = countDataInTable($dbh, $tableName, $tableData['id']);
    if($countData > 0){
      updateTable($dbh, $tableName, $countData, $tableData['id'], $tableData['fields'], $tableData['skipline']);
    }
  }
}

/**
 * Connect to the database
 * @param array $database Database's informations
 * @return PDO
 */
function bddConnect(array $database){
  /*
    Expect :
    $database = [
      "type" => "<DB_TYPE>",
      // optionnal :
      "host" => "<HOST>",
      "port" => "<PORT>",
      "dbname" => "<DB_NAME>",
      "user" => "<USER>",
      "password" => "<PASSWORD>",
      "schema" => "<SCHEMA>",
      "charset" => "<CHARSET>"
      "path" => "<PATH>"
    ]
  */
  $dbh = null;
  try {
    $param = [];
    $type = "";
    if(isset($database["type"])){
      $type = $database["type"];
      unset($database["type"]);
    }else{
      throw new Exception("Missing database.type in json!");
    }
    if(isset($database["path"])){
      $param[] = $database["path"];// for sqlite
      unset($database["path"]);
    }
    foreach ($database as $key => $value) {
      $param[$key] = "$key=$value";
    }
    if($type == "mysql" || $type == "oci"){
      $user = $param['user'];
      $password = $param['password'];
      unset($param['user']);
      unset($param['password']);
      $param = implode(";", $param);
      $dbh = new PDO("$type:$param", $user, $password);
    }else{
      $param = implode(";", $param);
      $dbh = new PDO("$type:$param");
    }
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  } catch(PDOException $e) {
    throw new Exception($e->getMessage());
  }
  return $dbh;
}

/**
 * Check fields
 * @param array &$fields Fields
 * @param string $tableName Table name
 */
function checkFields(array &$fields, string $tableName){
  /*
  Expect :
    $fields => [
      "<FIELD_NAME_1>" => [
        "function" => "<FUNCTION_NAME>",
        "param" => [
          "<PARAMETER_1>",
          "<PARAMETER_2>",
          ...
        ]
      ],
      "<FIELD_NAME_2>" => [
        "function" => "<FUNCTION_NAME>",
        "param" => [
          "<PARAMETER_1>",
          ...
        ]
      ],
      ...
    ]
  */
  foreach ($fields as $fieldName => $fielData) {
    if(!empty($fielData['sameAs'])){
      continue;
    }
    if(isset($fielData['setValue'])){
      continue;
    }
    if(empty($fielData['function'])){
      throw new Exception("Missing tables.$tableName.fields.$fieldName.function in json!");
    }
    if(!function_exists(FUNCTION_PREFIX.$fielData['function'])){
      throw new Exception("In json, for tables.$tableName.fields.$fieldName.function, function ".FUNCTION_PREFIX.$fielData['function']." (".$fielData['function'].") doesn't exists!");
    }
    $fields[$fieldName]['function'] = FUNCTION_PREFIX.$fielData['function'];
  }
}

/**
 * Throw exception if forbidden character found.
 * @param string $string 
 * @return true if OK
 */
function checkForbiddenChar(string $string){
  foreach (["'", '"', '%', ' ', ',', '$', '-', '.', ';', '`', '\\'] as $char) {
    if(strpos($string, $char) !== false){
      throw new Exception("Forbidden character «$char» found in string.");
      return false;
    }
  }
  return true;
}

/**
 * Count data in table
 * @param $dbh PDO
 * @param string $tableName Table name
 * @param array $ids Id's name
 * @return int
 */
function countDataInTable($dbh, string $tableName, array $ids){
  checkForbiddenChar($ids[0]);
  $count =$dbh->query("SELECT count(".$ids[0].") FROM $tableName")->fetchAll(PDO::FETCH_COLUMN, 0);//FIXME escape char
  if(!empty($count)){
    return $count[0];
  }else{
    return 0;
  }
}

/**
 * Update table
 * @param $dbh PDO
 * @param string $tableName Table name
 * @param int $countData Count of data in table
 * @param array $idsName Ids' name
 * @param array $fields Fields
 * @param array $skipline Skipline
 */
function updateTable($dbh, string $tableName, int $countData, array $idsName, array $fields, array $skipline){
  if(defined("QUERY_OFFSET") && QUERY_OFFSET !== null && ((int) QUERY_OFFSET) > 0){
    if(((int) QUERY_OFFSET) < $countData ){
      $queryOffset = (int) QUERY_OFFSET;
    }else{
      throw new Exception("No data.\nQUERY_OFFSET=".((int) QUERY_OFFSET)." >= countData=".$countData);
    }
  }else{
    $queryOffset = 0;
  }
  if(defined("QUERY_LIMIT_TOTAL_ROW") && QUERY_LIMIT_TOTAL_ROW !== null && QUERY_LIMIT_TOTAL_ROW > 0){
    if($queryOffset + (int) QUERY_LIMIT_TOTAL_ROW < $countData){
      $countData = $queryOffset + (int) QUERY_LIMIT_TOTAL_ROW;
    }
  }
  if(defined("QUERY_NB_ROW") && QUERY_NB_ROW !== null && QUERY_NB_ROW > 0){
    $nbRow = (int) QUERY_NB_ROW;
  }else{
    $nbRow = 10;
  }

  $queryLimit = ($nbRow < $countData - $queryOffset ? $nbRow : $countData - $queryOffset);

  $newDataKey = [];
  foreach ($fields as $fieldName => $fieldData) {
    checkForbiddenChar("$fieldName=:$fieldName");
    $newDataKey[] = "$fieldName=:$fieldName";
  }
  $idsNameKey = [];
  foreach ($idsName as $fieldName) {
    checkForbiddenChar("$fieldName=:$fieldName");
    $idsNameKey[] = "$fieldName=:$fieldName";
  }

  $updateSql = "UPDATE $tableName SET ".implode(", ", $newDataKey)." WHERE ".implode(", ", $idsNameKey).";";
  $num = 1;
  while ( $queryOffset <= $countData) {
    $order = $idsName[0];
    checkForbiddenChar("$tableName");
    checkForbiddenChar("$order");
    $sth = $dbh->prepare("SELECT * FROM $tableName ORDER BY $order ASC LIMIT :queryLimit OFFSET :queryOffset;");
    $sth->bindValue(':queryLimit', $queryLimit);
    $sth->bindValue(':queryOffset', $queryOffset);
    $sth->execute();
    $dataLines = $sth->fetchAll(PDO::FETCH_ASSOC);
    if(!empty($dataLines)){
      foreach ($dataLines as $data) {
        $newData = [];
        if(!empty($skipline)){
          $skip = true;
          // seach if all values match
          foreach ($skipline as $filedName => $values) {
            if(!empty($data[$fieldName])){
              $skip &= in_array($data[$fieldName], $values);
            }else{
              $skip = false;
              break;
            }
          }
          if($skip){
            // we don't change this line
            continue;
          }
        }
        foreach ($fields as $fieldName => $fieldData) {
          if(!empty($fieldData['skip']) && in_array($data[$fieldName], $fieldData['skip'])){
            // we don't change this field
            continue;
          }
          if(!empty($fieldData['sameAs'])){
            if(isset($newData[$fieldData['sameAs']])){
              // copy the field
              $newData[$fieldName] = $newData[$fieldData['sameAs']];
              $data[$fieldName] = $newData[$fieldName];
            }
          }elseif(isset($fieldData['setValue'])){
            $newData[$fieldName] = $fieldData['setValue'];
            $data[$fieldName] = $fieldData['setValue'];
          }else{
            $newData[$fieldName] = $fieldData['function']($fieldName, $data, (isset($fieldData['param'])?$fieldData['param']:[]));
            $data[$fieldName] = $newData[$fieldName];
          }
        }
        if(!empty($newData)){
          $sth = $dbh->prepare($updateSql);
          foreach ($newData as $key => $value) {
            $sth->bindValue(":$key", $value);
          }
          foreach ($idsName as $key) {
            $sth->bindValue(":$key", $data[$key]);
          }

          if(defined("DONT_SAVE") && DONT_SAVE==true){
            echo "\n";
            print_r($data);
            echo "\n";
          }else{
            try {
              $sth->execute();
            } catch(PDOException $e) {
              $msg = $e->getMessage();
              $msg .= "\ndata :\n".var_export($data, true);
              throw new Exception($msg);
            }
          }
          if(VERBOSE){
            printf("\r%-30s","$num/$countData, $order:".$data[$order]);
          }
          $num++;
        }
      }
    }

    $queryOffset += $queryLimit;
  }
  if(VERBOSE){
    printf("\r%-30s\n","Done");
  }
}

/**
 * Import file in $GLOBALS['dico']
 * @param string $fileName File name
 */
function importFile(string $fileName){
  if(isset($GLOBALS['dico'][$fileName])){
    return;
  }
  if(!is_file("./dico/$fileName")){
    throw new Exception("./dico/$fileName is not a file");
  }
  if (($handle = fopen("./dico/$fileName", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
      $GLOBALS['dico'][$fileName][] = $data;
    }
    fclose($handle);
  }
}

/**
 * Get parameter
 * @param ?array $param Parameters
 * @param string $key Parameter name
 * @param string $defaultValue Default value
 * @return string
 */
function getParam(?array $param, string $key, string $defaultValue){
  if(!is_array($param)){
    return $defaultValue;
  }elseif(!empty($param[$key])){
    return $param[$key];
  }else{
    return $defaultValue;
  }
}
?>
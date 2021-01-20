<?php

/**
 * Loads info from shp files and converts the data to usable WKT.
 *
 * Author: Brandon Morrison.
 *
 * Credit: Much inspiration came from the following sources.
 *   - http://www.easywms.com/easywms/?q=en/node/78
 *   - Drupal's Geo/Spatial Tools modules. (http://drupal.org/project/geo, http://drupal.org/project/spatial)
 */

class shpParser {
  const GEOPHP_PATH = "geophp/geoPHP.inc";

  private $geos_enabled = FALSE;

  private $shpFilePath;
  private $shpFile;
  private $headerInfo = array();
  private $shpData = array();

  public function __construct() {
    $dir = dirname(__FILE__) . "/" . self::GEOPHP_PATH;

    if (file_exists($dir)) { 
      require_once $dir;
      $this->geos_enabled = geoPHP::geosInstalled();
    }
  }
  
  public function load($path) {
    $this->shpFilePath = $path;
    $this->shpFile = fopen($this->shpFilePath, "rb");
    $this->loadHeaders();    
    $this->loadRecords();

    fclose($this->shpFile);
  }
  
  public function headerInfo() {
    return $this->headerInfo;
  }
  
  public function getShapeData() {
    if (!$this->geos_enabled) {
      trigger_error("GEOS não está carregado, não será possível validar a integridade das geometrias ", E_USER_WARNING);
    }

    return $this->shpData;
  }

  private function loadPRJ(){
    $crs = -1;  // Padrão para shapes sem projeção

    // Identifica se ele possui arquivo prj 
    // #TODO: (Ele ainda não trata CaSe SenSitive)
    $prj = preg_replace("/.shp$/i", ".prj", $this->shpFilePath);
    
    // Interpretação do .prj
    // TODO: Melhorar o suporte para outras projeções
    // TODO: Integrar com a lib Proj4? Se sim, este if desaparece
    if (file_exists($prj)) {
      $prjFile = fopen($prj, "r");
      $prjWKT = trim(fgets($prjFile, 4096));
      fclose($prjFile);

      if (preg_match("/^GEOGCS/i", $prjWKT)) {        
        if ( // WGS84 
            preg_match('/D_WGS_1984/i', $prjWKT) ||                 // ESRI WKT (identificado pelo Nome do Datum)
            preg_match('/AUTHORITY["EPSG","4326"]/i', $prjWKT)      // OGC WKT (identificado pela Autoridade identificadora - EPSG)
            ) {          
          $crs = 4326;
        } else if ( // SIRGAS2000
            preg_match('/D_SIRGAS_2000/i', $prjWKT)  ||             // ESRI WKT (identificado pelo Nome do Datum)
            preg_match('/AUTHORITY["EPSG","4674"]/i', $prjWKT)      // OGC WKT (identificado pela Autoridade identificadora - EPSG)
          ) {          
          $crs = 4674;
        } else {      
          throw new Exception("Projeção não reconhecida: " . $prjWKT);
        }
      } else if (preg_match("/^PROJCS/i", $prjWKT)) {
        throw new Exception("Ainda não há suporte para SRC projetadas (UTM, Policônica)");  
      } else {
        throw new Exception("Suporta apenas WGS-84 e SIRGAS 2000 Geográficos");
      }
    }
    
    return $crs;    
  }
  
  private function geomTypes() {
    return array(
      0  => 'Null Shape',
      1  => 'Point',
      3  => 'PolyLine',
      5  => 'Polygon',
      8  => 'MultiPoint',
      11 => 'PointZ',
      13 => 'PolyLineZ',
      15 => 'PolygonZ',
      18 => 'MultiPointZ',
      21 => 'PointM',
      23 => 'PolyLineM',
      25 => 'PolygonM',
      28 => 'MultiPointM',
      31 => 'MultiPatch',
    );
  }
  
  private function geoTypeFromID($id) {
    $geomTypes = $this->geomTypes();
    
    if (isset($geomTypes[$id])) {
      return $geomTypes[$id];
    }
    
    return NULL;
  }
  
  private function loadHeaders() {
    fseek($this->shpFile, 24, SEEK_SET);
    $length = $this->loadData("N");
    fseek($this->shpFile, 32, SEEK_SET);
    $shape_type = $this->geoTypeFromID($this->loadData("V"));
    
    $bounding_box = array();
    $bounding_box["xmin"] = $this->loadData("d");
    $bounding_box["ymin"] = $this->loadData("d");
    $bounding_box["xmax"] = $this->loadData("d");
    $bounding_box["ymax"] = $this->loadData("d");
    
    $this->headerInfo = array(
      'length' => $length,
      'shapeType' => array(
        'id' => $shape_type,
        'name' => $this->geoTypeFromID($shape_type),
      ),
      'boundingBox' => $bounding_box,
      'crs' => $this->loadPRJ(),   // 4326, 4674, -1 ou erro
    );
  }
  
  private function loadRecords() {
    fseek($this->shpFile, 100);
    
    while(!feof($this->shpFile)) {
      $record = $this->loadRecord();
      if(!empty($record['geom'])){
        $this->shpData[] = $record;
      }
    }
  }
  
  /**
   * Low-level data pull.
   * @TODO: extend to enable pulling from shp files directly, or shp files in zip archives.
   */
  
  private function loadData($type) {
    $type_length = $this->loadDataLength($type);
    if ($type_length) {
      $fread_return = fread($this->shpFile, $type_length);
      if ($fread_return != '') {
        $tmp = unpack($type, $fread_return);
        return current($tmp);
      }
    }
    
    return NULL;
  }
  
  private function loadDataLength($type) {
    $lengths = array(
      'd' => 8,
      'V' => 4,
      'N' => 4,
    );
    
    if (isset($lengths[$type])) {
      return $lengths[$type];
    }
    
    return NULL;
  }
  
  // shpRecord functions.
  private function loadRecord() {
    $recordNumber = $this->loadData("N");
    $this->loadData("N"); // unnecessary data.
    $shape_type = $this->loadData("V");
    
    $record = array(
      'shapeType' => array(
        'id' => $shape_type,
        'name' => $this->geoTypeFromID($shape_type),
      ),
    );
    
    switch($record['shapeType']['name']){
      case 'Null Shape':
        $record['geom'] = $this->loadNullRecord();
        break;
      case 'Point':
        $record['geom'] = $this->loadPointRecord();
        break;
      case 'PolyLine':
        $record['geom'] = $this->loadPolyLineRecord();
        break;
      case 'Polygon':
        $record['geom'] = $this->loadPolygonRecord();
        //$record['geom'] = $this->loadPolygonRecordSdo();
        break;
      case 'MultiPoint':
        $record['geom'] = $this->loadMultiPointRecord();
        break;
      default:
        // $setError(sprintf("The Shape Type '%s' is not supported.", $shapeType));
        break;
    }

    // Inclui no Array GEOM um item chamado valid, resultado de validação pelo GEOS.
    // Caso o item "valid" não exista, é porque não foi validado
    if (array_key_exists("geom", $record)) {
      $wkt = $record['geom']['wkt'];

      if ($this->geos_enabled && !is_null($wkt)) {
        $valid = geoPHP::load($wkt,'wkt')->checkValidity();
        $record['geom'] = array_merge($record['geom'], $valid);
      }
    }
    
    return $record;
  }
  
  private function loadPoint() {
    $data = array();
    $data['x'] = $this->loadData("d");
    $data['y'] = $this->loadData("d");
    //$data['x'] = number_format($this->loadData("d"), 12);
    //$data['y'] = number_format($this->loadData("d"), 12);
    return $data;
  }
  
  private function loadNullRecord() {
    return array();
  }
  
  private function loadPolyLineRecord() {
    $return = array(
      'bbox' => array(
        'xmin' => $this->loadData("d"),
        'ymin' => $this->loadData("d"),
        'xmax' => $this->loadData("d"),
        'ymax' => $this->loadData("d"),
      ),
    );
    
    $geometries = $this->processLineStrings();
    
    $return['numGeometries'] = $geometries['numParts'];
    if ($geometries['numParts'] > 1) {
      $return['wkt'] = 'MULTILINESTRING(' . implode(', ', $geometries['geometries']) . ')';
    }
    else {
      $return['wkt'] = 'LINESTRING(' . implode(', ', $geometries['geometries']) . ')';
    }
        
    return $return;
  }
  
  private function loadPolygonRecord() {
    $return = array(
      'bbox' => array(
        'xmin' => $this->loadData("d"),
        'ymin' => $this->loadData("d"),
        'xmax' => $this->loadData("d"),
        'ymax' => $this->loadData("d"),
      ),
    );
  
    $geometries = $this->processLineStrings();
    
    $return['numGeometries'] = $geometries['numParts'];
    if ($geometries['numParts'] > 1) {
      $return['wkt'] = 'MULTIPOLYGON((' . implode('), (', $geometries['geometries']) . '))';
    }
    else {
      $return['wkt'] = 'POLYGON(' . implode(', ', $geometries['geometries']) . ')';
    }
    
    return $return;
  }

  private function loadPolygonRecordSdo() {
    $return = array(
      'bbox' => array(
        'xmin' => $this->loadData("d"),
        'ymin' => $this->loadData("d"),
        'xmax' => $this->loadData("d"),
        'ymax' => $this->loadData("d"),
      ),
    );

    $geometries = $this->processLineStrings();

    $return['numGeometries'] = $geometries['numParts'];
    for($x=0;$x<count($geometries['geometries']);$x++){
        $geometries['geometries'][$x] = str_replace(", ", ",", $geometries['geometries'][$x]);
        $geometries['geometries'][$x] = str_replace(" ", ",", $geometries['geometries'][$x]);
    }
    if ($geometries['numParts'] == 1) {
//            for($x=0;$x<count($geometries['geometries']);$x++){
//                $geometries['geometries'][$x] = str_replace(" ", ",", $geometries['geometries'][$x]);
//            }
        //echo "<hr />".var_dump($geometries['geometries'])."<hr />";
        $return['sdo'] = implode(',', $geometries['geometries']);
    }else{ // Multipolygon
        $return['sdo'] = '( ' . implode(',', $geometries['geometries']) . ')';
    }
    return $return;
  }
  

  /**
   * Process function for loadPolyLineRecord and loadPolygonRecord.
   * Returns geometries array.
   */
  
  private function processLineStrings() {
    $numParts = $this->loadData("V");
    $numPoints = $this->loadData("V");
    $geometries = array();
    
    $parts = array();
    for ($i = 0; $i < $numParts; $i++) {
      $parts[] = $this->loadData("V");
    }
    
    $parts[] = $numPoints;
    
    $points = array();
    for ($i = 0; $i < $numPoints; $i++) {
      $points[] = $this->loadPoint();
    }
    
    if ($numParts == 1) {
      for ($i = 0; $i < $numPoints; $i++) {
        //$geometries[] = sprintf('%f %f', $points[$i]['x'], $points[$i]['y']); // Aqui ele deixa as coordenadas com 6 casas decimais
        //array_push($geometries, number_format($points[$i]['x'],13), number_format($points[$i]['y'],13));
        array_push($geometries, number_format($points[$i]['x'],13)." ".number_format($points[$i]['y'],13));
      }
      
    }
    else {
      for ($i = 0; $i < $numParts; $i++) {
        $my_points = array();
        for ($j = $parts[$i]; $j < $parts[$i + 1]; $j++) {
          //$my_points[] = sprintf('%f %f', $points[$j]['x'], $points[$j]['y']);
          //$my_points[] = ($points[$j]['x'], $points[$j]['y']);
          //array_push($my_points, number_format($points[$j]['x'],13), number_format($points[$j]['y'],13));
          array_push($my_points, number_format($points[$j]['x'],13)." ".number_format($points[$j]['y'],13));
        }
        $geometries[] = '(' . implode(', ', $my_points) . ')';
      }
    }
    
    return array(
      'numParts' => $numParts,
      'geometries' => $geometries,
    );
  }
  
  private function loadMultiPointRecord() {
    $return = array(
      'bbox' => array(
        'xmin' => $this->loadData("d"),
        'ymin' => $this->loadData("d"),
        'xmax' => $this->loadData("d"),
        'ymax' => $this->loadData("d"),
      ),
      'numGeometries' => $this->loadData("d"),
      'wkt' => '',
    );
    
    $geometries = array();
    
    for ($i = 0; $i < $this->shpData['numGeometries']; $i++) {
      $point = $this->loadPoint();
      $geometries[] = sprintf('(%f %f)', $point['x'], $point['y']);
    }
    
    $return['wkt'] = 'MULTIPOINT(' . implode(', ', $geometries) . ')';
    return $return;
  }
  
  private function loadPointRecord() {
    $point = $this->loadPoint();
    
    $return = array(
      'bbox' => array(
        'xmin' => $point['x'],
        'ymin' => $point['y'],
        'xmax' => $point['x'],
        'ymax' => $point['y'],
      ),
      'numGeometries' => 1,
      'wkt' => sprintf('POINT(%f %f)', $point['x'], $point['y']),
    );
    
    return $return;
  }
}

<?php
// PHP 7 notwendig
//die("deaktiviert - PHP 7 notwendig");

set_time_limit(0);
ini_set('memory_limit', '10240M');
require_once "vendor/autoload.php";
require_once "functions.php";
require_once "simple_html_dom.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

// CONFIG
$exportDIR = __DIR__ . "/exported-files/";

// create directory for exports if not existing
if(!file_exists($exportDIR)) {
  mkdir($exportDIR);
  echo "[Info] Directory {$exportDIR} was created\n";
}

$beruf = "carports";


// URL for page which will be scrapped, should start at page 1 without /Seite-
// at the end

$urlCity = "bundesweit";

$url = "https://www.gelbeseiten.de/Suche/".$beruf."/".$urlCity."?umkreis=50000";





if(strpos($url, "?") !== false) {
  $url = str_replace("?", "/Seite-1?", $url);
}

// fields mapping which will be scrapped
$searchedFields = [
  "company_name" => ["h2", "plaintext", 0],
  "address" => [".mod-AdresseKompakt__adress-text", "plaintext", 0],
  "phone" => [".mod-TelefonnummerKompakt__phoneNumber", "plaintext", 0],
  "website" => [".mod-WebseiteKompakt .mod-WebseiteKompakt__text", "data-webseitelink", 0], // website scraping
  "email" => [".contains-icon-email", "data-prg", 0],
];


$html = curl_get_page_dom($url);

$csvData = [];
$pagesCount = 1;

// find how many pages there are in the given URL
if(!is_null($html->find(".mod-paginierung li", -1))) {
  $pagesCount = $html->find(".mod-paginierung li", -1)->find("a", 0)->plaintext;
}

if ($pagesCount==1) {
	$hits = $html->find("#mod-TrefferlisteInfo", -1)->plaintext;
	$pagesCount = ceil($hits/50);
}


// parse data from each page
for($page = 1; $page <= $pagesCount; $page++) {
  if($page > 1) { // ignore first page load to reduce number of requests
    $html = curl_get_page_dom(str_replace("Seite-1", "Seite-{$page}", $url));
  }

  // go through each listing
  foreach($html->find(".mod.mod-Treffer") as $item) {
    $itemData = [];
    foreach($searchedFields as $fieldKey => $field) {
      if($fieldKey !== "buttons") {
        $fieldData = $item->find($field[0], $field[2]);
        
        // find filtered value for required field
        $fieldValue = (!is_null($fieldData) ? trim(preg_replace('/\s+/', ' ', str_replace("&amp;", "&", $fieldData->{$field[1]}))) : null);

        // clean up address and split into parts
        if($fieldKey == "address") {
          $street = null;
          $zip = null;
          $city = null;

          if(!is_null($fieldValue)) {
            if(strpos($fieldValue, ", ") !== false) {
              list($street, $address2) = explode(", ", $fieldValue);
            } else {
              $street = null;
              $address2 = $fieldValue;
            }

            list($zip, $city) = explode(" ", $address2);

            if(!is_numeric($zip)) {
              $zip = null;
              $city = null;
            }
          }

          $itemData["address_street"] = trim($street);
          $itemData["address_zip"] = trim($zip);
          $itemData["address_city"] = trim($city);


		  // print '<pre>';
		  // var_dump($itemData);
		  // die("pause");

        } 

      // added by Nazarii for website scraping
      else if ($fieldKey == "website") {
          $website = null;
          $website = (!is_null($fieldData)) ? base64_decode($fieldData->getAttribute($field[1])) : null;
          $itemData['website'] = $website;
        }
      // elseif ($fieldKey == "email") {
      //   $encodedEmailUrl = (!is_null($fieldData)) ? $fieldData->getAttribute($field[1]) : null;
      //   $decodedEmailUrl = base64_decode($encodedEmailUrl);

      //   $email = null;
      //   if ($decodedEmailUrl) {
      //       $parsedUrl = parse_url($decodedEmailUrl);
      //       // Ensure 'query' key exists before accessing
      //       print_r($parsedUrl);exit();
      //       $queryParams = isset($parsedUrl['query']) ? parse_str($parsedUrl['query']) : [];
      //       $email = isset($queryParams['email']) ? $queryParams['email'] : null;
      //   }
      //   $itemData['email'] = $email;
      // }
		else {
          $itemData[$fieldKey] = $fieldValue;
        }
      } 
    }

    $csvData[] = $itemData; // push row to main array
  }
}

// export data to CSV if there are results
if(count($csvData) > 0) {
  $fileName = $exportDIR . date('Y-m-d-H-i-s') ."-".$beruf."-".$urlCity."-export.csv";

  $spreadsheet = new Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();

  $sheet->fromArray(array_keys($csvData[0]), NULL); // set column headers
  $sheet->fromArray($csvData, NULL, 'A2');

  $writer = new Csv($spreadsheet);
  $writer->setUseBOM(true); // set UTF-8 encoding
  $writer->save($fileName);

  echo "[Info] " . count($csvData) . " rows were exported to {$fileName}\n";
} else {
  echo "[Warning] Nothing to export.\n";
}

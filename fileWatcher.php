#!/usr/bin/php
<?php
require __DIR__.'/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\Statement;

use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Exception\ApiException;

// create a log channel
$logger = new Logger('import');
$logger->pushHandler(new StreamHandler(__DIR__.'/logs/info.log', Logger::DEBUG));
$logger->info('Inicio Script');
echo 'Inicio Script';

// FIREBASE
$serviceAccount = ServiceAccount::fromJsonFile(__DIR__.'/firestore-test-1-todo-firebase-adminsdk-wzads-030e47b3cf.json');
$firebase = (new Factory)
    ->withServiceAccount($serviceAccount)
    ->create();
$database = $firebase->getDatabase();

/**
* solve JSON_ERROR_UTF8 error in php json_encode
* esta funcionsita me corrije un error que habia al tratar de hacerle json encode aun array con tildes
* en algunos textos
* @param  array $mixed El erray que se decia corregir
* @return array        Regresa el mismo array pero corrigiendo errores en la codificacion
*/
function utf8ize($mixed) {
   if (is_array($mixed)) {
       foreach ($mixed as $key => $value) {
           $mixed[$key] = utf8ize($value);
       }
   } else if (is_string ($mixed)) {
       return utf8_encode($mixed);
   }
   return $mixed;
}

function updateProducts($logger, $database){

    echo "se modificaron los productos perro hpta".PHP_EOL;
    /*
    * Con este comando uso git diff para comparar los archivos csv y sacar solo los
    * productos que se modificaron
    */
    $command = "git diff --no-index --color=always old-files/oldProds.csv /var/www/html/reactphp-couchdb-importer/observados/product.txt |perl -wlne 'print $1 if /^\e\[32m\+\e\[m\e\[32m(.*)\e\[m$/' > onlyModifiedProds.csv ";
    $output = shell_exec($command);

    /*
    * Como el resultado del comando anterior no me trae el encabezado del csv entonces
    * lo agrego con las sgtes lineas
    * mas info aqui * https://stackoverflow.com/questions/1760525/need-to-write-at-beginning-of-file-with-php
    */
    $file_data = "codigo;descripcion;precio1;cantInventario;_delete" . PHP_EOL;
    $file_data .= file_get_contents('onlyModifiedProds.csv');
    file_put_contents('onlyModifiedProds.csv', $file_data);

    /*
    * Elimino los archivos viejos que hayan en la carpeta de comparacion
    */
    $command = "rm -r old-files/oldProds.csv";
    shell_exec($command);

    /*
    * Copio el archivo csv con los productos nuevos a la carpeta de comparacion para
    * compararlos la proxima vez que se ejecute el cron
    */
    $command = "cp /var/www/html/reactphp-couchdb-importer/observados/product.txt old-files/oldProds.csv";
    $output = shell_exec($command);

    try {

        /**
         * Leo el archivo csv que contiene solo los productos por modificar
         * mediante la libreria csv de phpleague
         */
        $csv = Reader::createFromPath(__DIR__.'/onlyModifiedProds.csv', 'r');
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0); //set the CSV header offset
        $records = $csv->getRecords();

        /**
         * recorro los productos que tenia el archivo
         */
        foreach ($records as $offset => $record) {
            /**
             * De sap la descripcion trae el nombre la aplicacion la marca
             * y la unidad entones aqui extraigo dichos datos
             */
            $tituloApli = explode(".", $record['descripcion']);
            $aplMarca = explode("/", $tituloApli[1]);
            $marcaUnd = explode("_", $aplMarca[1]);

            $producto = [
                "_id"         => $record['codigo'],
                "titulo"      => utf8_encode($tituloApli[0]),
                "aplicacion"  => $aplMarca[0],
                "imagen"      => "https://www.igbcolombia.com/img_app/{$record['codigo']}.jpg",
                "categoria"   => null,
                "marcas"      => $marcaUnd[0],
                "unidad"      => $marcaUnd[1],
                "existencias" => intval($record['cantInventario']),
                "precio"      => intval($record['precio1'])
            ];
                
            echo "prod".$record['codigo']."\n";

            try {
                if($record['_delete'] == 'true'){
                    $newDelete = $database
                    ->getReference('products/'.$record['codigo'])
                    ->remove();
                }else{
                    $newPost = $database
                    ->getReference('products/'.$record['codigo'])
                    ->set($producto);
                }
                var_dump($producto);

            } catch (ApiException $e) {
                /** @var \Psr\Http\Message\RequestInterface $request */
                $request = $e->getRequest();
                /** @var \Psr\Http\Message\ResponseInterface|null $response */
                $response = $e->getResponse();

                echo $request->getUri().PHP_EOL;
                echo $request->getBody().PHP_EOL;

                if ($response) {
                    echo $response->getBody();
                }
            }

        }

    } catch (Throwable $e) {
        $logger->error($e->getMessage()." ".$e->getLine());
    }

}


$loop = React\EventLoop\Factory::create();
$inotify = new MKraemer\ReactInotify\Inotify($loop);

$inotify->add('/var/www/html/reactphp-couchdb-importer/observados/', IN_CLOSE_WRITE | IN_CREATE | IN_DELETE);
//$inotify->add('/var/log/', IN_CLOSE_WRITE | IN_CREATE | IN_DELETE);

$inotify->on(IN_CLOSE_WRITE, function ($path) use($logger, $database) {
    $logger->info('***********************************************************************************');
    $logger->info('File closed after writing: '.$path.PHP_EOL);
    echo '***********************************************************************************';
    echo 'File closed after writing: '.$path.PHP_EOL;

    if($path == "/var/www/html/reactphp-couchdb-importer/observados/product.txt"){
        updateProducts($logger, $database);
    }

    $logger->info('***********************************************************************************');
    echo 'File closed after writing: '.$path.PHP_EOL;
});

$inotify->on(IN_CREATE, function ($path) use($logger) {
    $logger->info('File created: '.$path.PHP_EOL);
});

$inotify->on(IN_DELETE, function ($path) use($logger) {
    $logger->info('File deleted: '.$path.PHP_EOL);
});

$loop->run();

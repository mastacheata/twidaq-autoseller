<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use GuzzleHttp\Exception\ClientException;
use Monolog\Logger;
use Monolog\Handler\RavenHandler;
use Monolog\Formatter\LineFormatter;

$config = json_decode(file_get_contents(__DIR__.'/config.json'), true);

$client = new Raven_Client($config['raven_dsn']);
$log = new Logger('twidaq-autoseller');
$handler = new RavenHandler($client);
$handler->setFormatter(new LineFormatter("%message%\n"));
$log->pushHandler($handler);

$stack = HandlerStack::create();

$middleware = new Oauth1($config['twidaq_api']);
$stack->push($middleware);

$client = new Client([
    'base_uri' => 'https://api.twidaq.com/',
    'handler' => $stack,
    'auth' => 'oauth'
]);

$response = $client->request('GET', '1/trader/portfolio.json', ['verify' => false]);
$portfolio = json_decode($response->getBody(), true);
$log->addDebug("Portfolio update", $portfolio);
$portfolio = json_decode($response->getBody());

$stocks = [];

$oldStocks = json_decode(file_get_contents(__DIR__.'/stocks.json'), true);

$changed = false;

foreach($portfolio as $item) {
	$newPrice = $item->stock->price;
	$stockId = $item->stock->code;
	$stocks[$stockId]['price'] = $newPrice;
	$stocks[$stockId]['volume'] = $item->volume;

    if (array_key_exists($stockId, $oldStocks) && $newPrice < $oldStocks[$stockId]['price']) {
        try {
            if($item->stock->ceo_code == 'Mastacheata' || $stockId == 'fabpot') {
                $item->volume = $item->volume <= 26 ? (intval($item->volume) - 1) : 25;
                if ($item->volume < 1) continue;
            }
            elseif($stockId == 'samsonginfo') {
                continue;
            }
            else {
                $item->volume = $item->volume <= 25 ? $item->volume : 25;
            }
            $log->addInfo("sell {$item->volume} of {$stockId}\n", ['item' => $item, 'stockId' => $stockId, 'newPrice' => $newPrice, 'oldPrice' => $oldStocks[$stockId]['price'], 'volume' => $item->volume]);
            $sellResponse = $client->request('GET', '1/trade/'.$stockId.'/sell.json?volume='.$item->volume.'&tweet=false&follow=false', ['verify' => false]);            
            file_put_contents(__DIR__.'/sales.txt', 'Sell '.($item->volume).' of '.$stockId.' at new price: '.$newPrice.', last good price was: '.($oldStocks[$stockId]['price']).'(Sold at: '.date('Y-m-d H:i:s').")\r\n", FILE_APPEND);
            file_put_contents(__DIR__.'/sales.log', $sellResponse->getBody()."\r\n", FILE_APPEND);
        }
        catch (ClientException $e)
        {
            $log->addError('Sell stock failed', ['exception' => $e->getMessage()]);
            file_put_contents(__DIR__.'/sales.log', $e->getMessage()."\r\n", FILE_APPEND);
        }
        $changed = true;
	}
}

$log->addDebug("Stocks in Portfolio", $stocks);
file_put_contents(__DIR__.'/stocks.json', json_encode($stocks));

#!/usr/bin/php
<?php
/* Create our load timestamp for now so that it's even */
$now = time();
/* Load in our settings */
require_once (__DIR__."/../settings/settings.php");
require_once (__DIR__."/../settings/accounts.php");
require_once (__DIR__."/../functions.php");
/* Create our SQL connection */
$sql = new mysqli ($MYSQL_HOST, $MYSQL_USER, $MYSQL_PASS, $MYSQL_DB);
/* See if we have a market data table */
$res = $sql->query("SELECT * FROM market_data LIMIT 1");
if (!$res) {
    /* We need to create our market data table */
    printf ("No market_data table exists, creating...");
    $qry = "CREATE TABLE market_data ".
        "(exchange VARCHAR(20) NOT NULL,".
        "market_name VARCHAR(12) NOT NULL,".
        "timestamp INT NOT NULL,".
        "last FLOAT NOT NULL,".
        "lowest_ask FLOAT NOT NULL,".
        "highest_bid FLOAT NOT NULL,".
        "percent_change FLOAT NOT NULL,".
        "base_volume FLOAT NOT NULL,".
        "quote_volume FLOAT NOT NULL,".
        "is_frozen INT NOT NULL,".
        "high_24_hr FLOAT NOT NULL,".
        "low_24_hr FLOAT NOT NULL,".
        "PRIMARY KEY (exchange,market_name,timestamp)) ENGINE = InnoDb;";
    $sql->query($qry);
    $sqy = "ALTER TABLE `market_data` ADD INDEX( `market_name`, `timestamp`, `last`);";
    $sql->query($qry);
    printf ("done\n");    
}
/* See if we have a balances table */
$res = $sql->query("SELECT * FROM balance LIMIT 1");
if (!$res) {
    /* We need to create our market data table */
    printf ("No balance table exists, creating...");
    $qry = "CREATE TABLE balance ".
        "(name VARCHAR(30) NOT NULL,".
        "timestamp INT NOT NULL,".
        "currency VARCHAR(8) NOT NULL,".
        "available FLOAT NOT NULL,".
        "onorder FLOAT NOT NULL,".
        "value FLOAT NOT NULL,".
        "account_id INT NOT NULL,".
        "PRIMARY KEY (name,timestamp,currency)) ENGINE = InnoDb;";
    $sql->query($qry);
    printf ("done\n");
};

/* Store the BPI in the market_data table */
printf ("[%s] Getting BPI data...", strftime("%c"));
$bpi = str_replace(',','',get_bpi ());

$qstr = "INSERT INTO market_data VALUES (".
    "'BPI',".
    "'USD_BTC',".$now.",".
    $bpi.",0,0,0,0,0,0,0,0)";
$sql->query ($qstr);
printf ("done.\n");

/* Get our exchange accounts and update market data */
$res = $sql->query ("SELECT * FROM accounts");
while ($acct = $res->fetch_assoc()) {
    /* Get our account information from type */
    foreach ($available_accounts as $aaccount) {
        if (!strcmp($acct["type"], $aaccount["name"])) break;
    }
    /* If we are not an exchange account, then just leave */
    if (strcmp($aaccount["type"], "Exchange")) continue;
    /* Create our class for the account */
    $cmd = "require_once(__DIR__.\"/../".$aaccount["file"]."\");";
    eval ($cmd);
    $cmd = "\$class = new ".$aaccount["class"]."('".
        $acct["api_key"]."','".$acct["api_secret"]."','".$acct["notes"]."');";
    eval ($cmd);
    printf ("[%s] Getting market data for ".$acct["type"]."...", strftime("%c"));
    /* Get our ticker information */
    $ticker = $class->get_ticker();
    foreach (array_keys($ticker) as $key) {
        $qstr = "INSERT INTO market_data VALUES (".
            "'".$acct["type"]."',".
            "'".$key."',".$now.",".
            $ticker[$key]["last"].",".
            $ticker[$key]["lowestAsk"].",".
            $ticker[$key]["highestBid"].",".
            $ticker[$key]["percentChange"].",".
            $ticker[$key]["baseVolume"].",".
            $ticker[$key]["quoteVolume"].",".
            $ticker[$key]["isFrozen"].",".
            $ticker[$key]["high24hr"].",".
            $ticker[$key]["low24hr"].")";
        $sql->query ($qstr);
    }
    printf ("done.\n");
}

/* Get all account balances and update the balance table */
printf ("[%s] Getting balances...", strftime("%c"));
$res = $sql->query ("SELECT * FROM accounts");
$qry = "INSERT INTO balance VALUES ";
while ($acct = $res->fetch_assoc()) {
    /* Get our account information from type */
    foreach ($available_accounts as $aaccount) {
        if (!strcmp($acct["type"], $aaccount["name"])) break;
    }
    /* Create our class for the account */
    $cmd = "require_once(__DIR__.\"/../".$aaccount["file"]."\");";
    eval ($cmd);
    $cmd = "\$class = new ".$aaccount["class"]."('".
        $acct["api_key"]."','".$acct["api_secret"]."','".$acct["notes"]."');";
    eval ($cmd);
    $balances = $class->getBalances();
    
    foreach ($balances as $balance) {
        $total = (double)$balance["available"] + (double)$balance["onorder"];
        /* Skip any empty values */
        if ($total == 0.0) continue;
        $usd_amount = get_usd_amount ($sql, $balance["currency"], $total, $bpi);
        $qry .= "('".$balance["name"]."',".
            $now.",'".$balance["currency"]."',".
            $balance["available"].",".$balance["onorder"].",".$usd_amount.",".
            $acct["id"]."),";
    }
}
$qry = substr($qry,0,-1).";";
$sql->query ($qry)."\n";
printf ("done.\n");

/* Check to see if we have miners to grab power from */
$res = $sql->query ("SELECT * FROM miners");
if ($res) {
    printf ("[%s] Getting miner power usage...", strftime("%c"));

    while ($miner = $res->fetch_assoc()) {
        if (strcmp($miner["account"], "static")) {
            $output = array();
            $cmd = "ssh ".$miner["account"]."@".$miner["hostname"]." nvidia-smi -q";
            exec ($cmd, $output);
            $power = 0.0;
            foreach ($output as $line) {
                if (strstr($line, "Power Draw")) {
                    $ex = explode(" ",explode(":", $line)[1]);
                    $power += $ex[1];
                }
            }
        } else {
            $power = floatval($miner["hostname"]);
        }
        $qry =  "INSERT INTO power_draw VALUES (".
            $miner["id"].",".
            $now.",".
            $power.")";
        $sql->query ($qry);        
    }
    printf ("done.\n");
}

/* If we have a pool account then update table information for it */
$res = $sql->query ("SELECT * FROM accounts");

while ($acct = $res->fetch_assoc()) {
    /* Get our account information from type */
    foreach ($available_accounts as $aaccount) {
        if (!strcmp($acct["type"], $aaccount["name"])) break;
    }
    /* If we are not an pool account, then just leave */
    if (strcmp($aaccount["type"], "Pool")) continue;
    /* Create our class for the account */
    $cmd = "require_once(__DIR__.\"/../".$aaccount["file"]."\");";
    eval ($cmd);
    $cmd = "\$class = new ".$aaccount["class"]."('".
        $acct["api_key"]."','".$acct["api_secret"]."','".$acct["notes"]."');";
    eval ($cmd);
    
    printf ("[%s] Updating mining data for %s...", strftime("%c"), $acct["name"]);
    $class->updateMiningData ($sql, $now);
    printf ("done.\n");
}
?>

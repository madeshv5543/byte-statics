<?php
//This scripts timestamps main chain units based on time interpolation from timestamp Byeball oracle
//and updates the mci_timestamps table.
//Should be run every minute from a cron job.

date_default_timezone_set('UTC');

$time_in = time();

$db = new SQLite3($_SERVER['HOME'].'/.config/obyte-hub/byteball.sqlite');
$stats_db = new SQLite3('stats.sqlite');
$stats_db->busyTimeout(30*1000);
$stats_db->exec("PRAGMA foreign_keys = 1");
//$stats_db->exec("PRAGMA journal_mode=WAL");
//$stats_db->exec("PRAGMA synchronous=FULL");
$stats_db->exec("PRAGMA temp_store=MEMORY");

$stats_db->exec('BEGIN');
/*
 * where are we ?
 */
 
$results = $stats_db->query("select max(main_chain_index) as max_MCI from mci_timestamps" );

$row = $results->fetchArray(SQLITE3_ASSOC);

$max_MCI = $row[ 'max_MCI' ];

$last_timestamped_mci = $max_MCI ? $max_MCI : 0;
echo "last mci ".$last_timestamped_mci."\n";

/*
 * first get timestamp oracle info
 */

$query = "select units.main_chain_index";
$query .= ", data_feeds.int_value";
$query .= " from units";
$query .= " left join unit_authors on unit_authors.unit = units.unit";
$query .= " left join data_feeds on data_feeds.unit = units.unit";
$query .= " where 1";
$query .= " and unit_authors.address='I2ADHGP4HL6J37NQAD73J7E5SKFIXJOT'";
$query .= " and data_feeds.feed_name='timestamp'";
$query .= " and units.main_chain_index > '$last_timestamped_mci' ";
$query .= " order by units.main_chain_index";

$results = $db->query( $query );

while ($row = $results->fetchArray(SQLITE3_ASSOC)) {

//	echo "<br>".print_r($row,true);
//	echo " " . date( 'Y-m-d H:i:s', round($row[ 'int_value' ]/1000) );

	$query = "insert OR IGNORE into mci_timestamps (main_chain_index, date) VALUES ($row[main_chain_index], '".date('Y-m-d H:i:s', round($row['int_value']/1000))."')";

	$stats_db->query($query);
    
}



/*
 * then complete by filling the holes
 */

$results = $stats_db->query("select main_chain_index, strftime('%s', date) as timestamp from mci_timestamps order by main_chain_index" );


$from_mci = 0;

while( $row = $results->fetchArray(SQLITE3_ASSOC) ){

	$to_mci = $row[ 'main_chain_index' ];
	$to_timestamp = $row[ 'timestamp' ];

	if( ! empty( $from_mci ) ){

		interpolate_timestamp( $from_mci, $from_timestamp, $to_mci, $to_timestamp );

	}

	$from_mci = $to_mci;
	$from_timestamp = $to_timestamp;
    

}

$stats_db->exec('COMMIT');



function interpolate_timestamp( $from_mci, $from_timestamp, $to_mci, $to_timestamp ){

//     echo "<br>interpolate_timestamp( $from_mci, $from_timestamp, $to_mci, $to_timestamp )";
    global $stats_db;
	$delta_time = $to_timestamp-$from_timestamp;
	$delta_mci = $to_mci - $from_mci;
    
//     echo "<br>delta_time: ".$delta_time;
//     echo "<br>delta_mci: ".$delta_mci;

	for( $mci = ($from_mci + 1); $mci<$to_mci; $mci++ ){
    
		$interpolated_time = round( $from_timestamp + ( $mci - $from_mci ) / $delta_mci * $delta_time ) ;
//         echo "<br>interpolated_time of mci $mci : ".date( 'Y-m-d H:i:s', $interpolated_time);
        
		$stats_db->query("insert into mci_timestamps (main_chain_index, date) VALUES($mci, '" . date( 'Y-m-d H:i:s', $interpolated_time) . "')" );
        
    
    }


}
 


$total_time = time() - $time_in;
echo "\n<br><br>done in " . $total_time . " sec\n";

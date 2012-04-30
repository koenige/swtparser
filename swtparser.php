<?php 
    
/**
 * SWT parser: Parsing binary Swiss Chess Tournament files
 *
 * Part of �Zugzwang Project�
 * http://www.zugzwang.org/projects/swtparser
 *
 * @author Gustaf Mossakowski, gustaf@koenige.org
 * @author Jacob Roggon, jacob@koenige.org
 * @copyright Copyright � 2005, 2012 Gustaf Mossakowski
 * @copyright Copyright � 2005 Jacob Roggon
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */

/*

SWT parser: parsing binary Swiss Chess Tournament files
Copyright (C) 2005, 2012 Gustaf Mossakowski, Jacob Roggon, Falco Nogatz

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Lesser General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

// required files
require_once 'fileparsing.php';

/**
 * Parses an SWT file from SwissChess and returns data in an array
 * Parst ein SWT file aus SwissChess und gibt Daten in Liste zur�ck
 *
 * @param string $filename
 * @return array
 *		array 'out' data for further processing
 *		array 'bin' data for marking up binary output
 */
function swtparser($filename) {
	$contents = zzparse_open($filename);
	if (!$contents) return false;
	
	// read common tournament data
	// Allgemeine Turnierdaten auslesen
	$tournament = zzparse_interpret($contents, 'allgemein');

	// common data lengths
	// Allgemeine Datenl�ngen
	define('LEN_PAARUNG', 19);
	if (FILEVERSION >= 800) {
		// Mannschaftsturnier mit zus�tzlichen Mannschaftsdaten
		define('START_PARSING', 13384); // = 0x3448
		define('LEN_KARTEI', 655);		// = 0x28F
	} else {
		// mind. Einzelturnier vor Version 8
		define('START_PARSING', 3894);	// = 0xF36
		define('LEN_KARTEI', 292);		// = 0x124
	}

	// index card for teams
	//	Karteikarten Mannschaften
	if ($tournament['out']['Mannschaftsturnier']) {
		list($tournament['out']['Teams'], $bin) = swtparser_records($contents, $tournament['out'], 'Teams');
		$tournament['bin'] = array_merge($tournament['bin'], $bin);
	}

	// index card for players
	//	Karteikarten Spieler
	list($tournament['out']['Spieler'], $bin) = swtparser_records($contents, $tournament['out'], 'Spieler');
	$tournament['bin'] = array_merge($tournament['bin'], $bin);

	// team fixtures
	//	Mannschaftspaarungen
	if ($tournament['out']['Mannschaftsturnier']) {
		list($tournament['out']['Mannschaftspaarungen'], $bin) = swtparser_fixtures($contents, $tournament['out'], 'Teams');
		$tournament['bin'] = array_merge($tournament['bin'], $bin);
	}

	// player fixtures
	//	Einzelpaarungen
	list($tournament['out']['Einzelpaarungen'], $bin) = swtparser_fixtures($contents, $tournament['out'], 'Spieler');
	$tournament['bin'] = array_merge($tournament['bin'], $bin);
	return $tournament;
}

/**
 * Parses record cards for single players and teams
 *
 * @param array $contents
 * @param array $tournament
 * @param string $type ('Spieler', 'Teams')
 * @return array
 */
function swtparser_records($contents, $tournament, $type = 'Spieler') {
	$startval = (START_PARSING 
		+ ($tournament['Teilnehmerzahl'] * $tournament['maximale Runden'] * LEN_PAARUNG)
		+ ($tournament['Mannschaftszahl'] * $tournament['maximale Runden'] * LEN_PAARUNG));
	
	switch ($type) {
	case 'Spieler':
		$maxval = $tournament['Teilnehmerzahl'];
		$structfile = 'spieler';
		break;
	case 'Teams':
		$startval = ($startval + $tournament['Teilnehmerzahl'] * LEN_KARTEI);
		$maxval = $tournament['Mannschaftszahl'];
		$structfile = 'mannschaft';
		break;
	}

	$records = array();
	$bin = array();
	for ($i = 0; $i < $maxval; $i++) {
		$data = zzparse_interpret($contents, $structfile, $startval + $i * LEN_KARTEI, LEN_KARTEI);
		$bin = array_merge($bin, $data['bin']);
		if ($type === 'Teams') {
			$records[$data['out']['MNr.-ID']] = $data['out'];
		} else {
			$records[$data['out']['TNr.-ID']] = $data['out'];
		}
	}
	return array($records, $bin);
}

/**
 * Parses fixtures for single players and teams
 *
 * @param array $contents
 * @param array $tournament
 * @param string $type ('Spieler', 'Teams')
 * @return array [player ID][round] = data
 */
function swtparser_fixtures($contents, $tournament, $type = 'Spieler') {
	$fixtures = array();
	$runde = 1;
	$ids = array_keys($tournament[$type]);
	$index = -1;
	$startval = START_PARSING;
	
	switch ($type) {
	case 'Spieler':
		$max_i = $tournament['maximale Runden'] * $tournament['Teilnehmerzahl'];
		$structfile = 'einzelpaarungen';
		$name_field = 'Spielername';
		break;
	case 'Teams':
		$startval += $tournament['maximale Runden'] * $tournament['Teilnehmerzahl'] * LEN_PAARUNG;
		$max_i = $tournament['maximale Runden'] * $tournament['Mannschaftszahl'];
		$structfile = 'mannschaftspaarungen';
		$name_field = 'Mannschaft';
		break;
	}
	
	$bin = array();
	for ($i = 0; $i < $max_i; $i++) {
		// Teams, starting with index 0
		// Mannschaften, beginnend mit Index 0
		if ($runde == 1) $index++;
		$id = $ids[$index];
		$pos = $startval + $i * LEN_PAARUNG;
		$data = zzparse_interpret($contents, $structfile, $pos, LEN_PAARUNG);
		$bin = array_merge($bin, $data['bin']);
		if (isset($tournament[$type][$data['out']['Gegner']])) {
			$data['out']['Gegner_lang'] = $tournament[$type][$data['out']['Gegner']][$name_field];
		} elseif ($data['out']['Gegner'] !== '00') {
			$data['out']['Gegner_lang'] = 'UNKNOWN '.$data['out']['Gegner'];
		} else {
			$data['out']['Gegner_lang'] = '';
		}
		$fixtures[$id][$runde] = $data['out'];
		// increment round, when reaching maximum rounds, start over again
		// Runde einen erhoehen, nach max. Rundenzahl wieder von vorne beginnen
		if ($runde == $tournament['maximale Runden']) $runde = 1; 
		else $runde++;
	}
	return array($fixtures, $bin);
}

?>
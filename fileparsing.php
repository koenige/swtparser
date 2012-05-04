<?php 

/**
 * Parsing functions for reading binary files
 *
 * Part of �Zugzwang Project�
 * http://www.zugzwang.org/projects/swtparser
 *
 * @author Gustaf Mossakowski, gustaf@koenige.org
 * @copyright Copyright � 2005, 2012 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */

/**
 * Read a file
 *
 * @param string $filename
 * @return string
 * @todo better error handling
 */
function zzparse_open($filename) {
	if (!$filename) {
		echo '<p>Please choose a filename! / Bitte w�hlen Sie einen Dateinamen aus!</p>';
		return false;
	}
	$handle = fopen($filename, "rb");
	$contents = fread($handle, filesize($filename));
	fclose($handle);
	return $contents;
}

/**
 * Reads a definition file for a part of the file structure
 *
 * Definition files may have several comment lines starting with # at the start.
 * The following lines must each contain the following values, separated by a
 * tabulator: 
 *		string starting hexadecimal code, 
 *		string ending hexadecimal code,
 *		string type (asc, bin, b2a, boo)
 *		string content = description of what is the data about
 * @param string $part
 * @param string $type (optional, 'fields' or 'replacements')
 * @return array structure of part
 * @see zzparse_interpret()
 */
function zzparse_structure($part, $type = 'fields') {
	static $structure;
	// check if we already have read the structure for this part
	if (!empty($structure[$part])) return $structure[$part];
	
	$dirs = array();
	if (defined('FILEVERSION')) {
		$max = strlen(FILEVERSION);
		for ($i = 0; $i < $max; $i++) {
			$dirs[] = '-v'.substr(FILEVERSION, 0, $max - $i)
				.(str_repeat('x', $i));
		}
	}
	$dirs[] = '';
	foreach ($dirs as $dir) {
		$filename = 'structure'.$dir.'/'.$part.'.txt';
		if (!file_exists($filename)) $filename = '';
		else break;
	}
	if (!$filename) {
		die(sprintf('Structure file for <code>%s</code> does not exist.', $part));
	}
	$defs = file($filename);
	switch ($type) {
		case 'fields': $elements = 4; break;
		case 'replacements': $elements = 2; break;
		default: die('Not a valid choice for a structural file.');
	}
	$structure[$part] = array();
	foreach ($defs as $no => $def) {
		if (substr($def, 0, 1) === '#') continue;
		$def = rtrim($def);
		$line = explode("\t", $def);
		if (!isset($line[$elements-1])) $line[$elements-1] = '';
		if (count($line) != $elements) {
			die(sprintf('Structure file for <code>%s</code> is invalid (line %s)', $part, $no+1));
		}
		switch ($type) {
		case 'fields':
			list($my['begin'], $my['end'], $my['type'], $my['content']) = $line;
			if (!$my['content']) {
				$my['content'] = 'BIN '.$my['begin'].($my['end'] ? '-'.$my['end'] : '');
			}
			$structure[$part][] = $my;
			break;
		case 'replacements':
			list($my['key'], $my['replacement']) = $line;
			$structure[$part][$my['key']] = $my['replacement'];
			break;
		}
	}
	return $structure[$part];
}

/**
 * Interprets binary data depending on structure
 *
 * @param string $binary binary data
 * @param string $part name of structural file for this part
 * @param int $start (optional, looks at just a part of the data)
 * @param int $end (optional, looks at just a part of the data)
 * @return array data 
 *		array out: field title => value
 *		array bin: begin, end and type (for development)
 */
function zzparse_interpret($binary, $part, $start = 0, $end = false) {
	if ($end) $binary = substr($binary, $start, $end);
	$data = array();
	$data['out'] = array();
	$data['bin'] = array();
	$structure = zzparse_structure($part);

	foreach ($structure as $line) {
		$substring = zzparse_binpos($binary, $line['begin'], $line['end']);
		$const = false;
		if (strtoupper($line['content']) === $line['content']
			AND substr($line['content'], 0, 1) === '_'
			AND substr($line['content'], -1) === '_') {
			$const = true;
			$line['content'] = substr($line['content'], 1, -1);
		}
		$data['bin'][] = array(
			'begin' => hexdec($line['begin']) + $start, 
			'end' => ($line['end'] ? hexdec($line['end']) : hexdec($line['begin'])) + $start,
			'type' => $line['type'],
			'content' => $line['content']
		);
		switch ($line['type']) {
		case 'asc':
			// Content is in ASCII format
			// cuts starting byte with value 00 which marks the end of string, 
			// rest is junk data
			$data['out'][$line['content']] = zzparse_tonullbyte($substring);
			break;
			
		case 'bin':
			// Content is binary value
			$data['out'][$line['content']] = zzparse_binary($substring);
			break;

		case 'b2a':
			// Content is hexadecimal value
			$data['out'][$line['content']] = hexdec(zzparse_binary($substring));
			break;

		case 'int':
			// Content is integer value, little endian
			$data['out'][$line['content']] = hexdec(bin2hex(($substring)));
			break;

		case 'inb':
			// Content is integer value, big endian
			$data['out'][$line['content']] = hexdec(bin2hex(strrev($substring)));
			break;

		case 'boo':
			// Content is boolean
			$substring = chop(zzparse_binary($substring));
			switch ($substring) {
				case 'FF': $data['out'][$line['content']] = 1; break;
				case '00': $data['out'][$line['content']] = 0; break;
				default: $data['out'][$line['content']] = NULL; break;
			}
			break;
		
		case 'sel':
			$area = strtolower($line['content']);
			if ($pos = strpos($area, ' ')) $area = substr($area, 0, $pos);
			$area .= '-selection';
			$selection = zzparse_structure($area, 'replacements');
			$value = zzparse_binary($substring);
			if (!in_array($value, array_keys($selection))) {
				$data['out'][$line['content']] = 'UNKNOWN: '.$value;
			} else {
				$data['out'][$line['content']] = $selection[$value];
			}
		}

		// all uppercase variables: define as constants as well
		if ($const) {
			define($line['content'], $data['out'][$line['content']]);		
		}
	}
	return $data;
}

/**
 * Get binary substring from file contents
 *
 * @param string $val string that is searched
 * @param string $start hex value of first position of substring
 * @param string $end hex value of last position of substring; optional: start
 *		value will be used if substring should be only one byte long
 * @param int $length (optional)
 * @return string
 */
function zzparse_binpos($val, $start, $end = false, $length = false) {
	// if it's only one byte long, end = start
	if (!$end) $end = $start;
	if ($length) {
		$output = substr($val, hexdec($start), $length);
	} else {
		$output = substr($val, hexdec($start), (hexdec($end)-hexdec($start)+1));
	}
	return $output;
}

/**
 * Returns hex value for each byte of a string, separated by spaces
 *
 * @param string $val
 * @return string
 */
function zzparse_binary($val) {
	$output = '';
	$len = strlen($val);
	for ($a = 0; $a < $len; $a++)
		$bytes[] = bin2hex($val[$a]);
	if (empty($bytes)) {
		// @todo: error, no value was given
		return 'XX';
	}
	foreach ($bytes as $byte) {
		if (strlen($byte) == 1) $byte = '0'.$byte;
		$output[] = strtoupper($byte);
	}
	$output = implode(' ', $output);
	return $output;
}

/**
 * Returns substring from begin of string to first occurence of a null byte
 *
 * @param string $val
 * @return string
 */
function zzparse_tonullbyte($val) {
	if (strstr($val, chr('00'))) {
		$output = substr($val, 0, strpos($val, chr('00')));
	} else {
		$output = $val;
	}
	return $output;
}

?>
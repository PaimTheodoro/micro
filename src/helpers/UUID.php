<?php

namespace Psf\Helper;

class UUID{

	const UUID_TIME	= 1;
	const UUID_NAME_MD5	= 3;	
	const UUID_RANDOM = 4;	
	const UUID_NAME_SHA1 = 5;	

	const FMT_FIELD	= 100;
	const FMT_STRING = 101;
	const FMT_BINARY = 102;
	const FMT_QWORD	= 1;
	const FMT_DWORD	= 2;
	const FMT_WORD = 4;	
	const FMT_SHORT	= 8;	
	const FMT_BYTE = 16;	
	const FMT_DEFAULT = 16;

	static private $m_uuid_field = [
		'time_low' => 0,
		'time_mid' => 0,
		'time_hi' => 0,
		'clock_seq_hi' => 0,
		'clock_seq_low' => 0,
		'node' => []
	];

	static private $m_generate = [
		self::UUID_TIME => "generateTime",
		self::UUID_RANDOM => "generateRandom",
		self::UUID_NAME_MD5 => "generateNameMD5",
		self::UUID_NAME_SHA1 => "generateNameSHA1"
	];

	static private $m_convert = [
		self::FMT_FIELD => [
			self::FMT_BYTE => "conv_field2byte",
			self::FMT_STRING => "conv_field2string",
			self::FMT_BINARY => "conv_field2binary"
		],
		self::FMT_BYTE => [
			self::FMT_FIELD => "conv_byte2field",
			self::FMT_STRING => "conv_byte2string",
			self::FMT_BINARY => "conv_byte2binary"
		],
		self::FMT_STRING => [
			self::FMT_BYTE => "conv_string2byte",
			self::FMT_FIELD => "conv_string2field",
			self::FMT_BINARY => "conv_string2binary"
		],
	];

	static private function swap32($x){
		return (($x & 0x000000ff) << 24) | (($x & 0x0000ff00) << 8) |
			(($x & 0x00ff0000) >> 8) | (($x & 0xff000000) >> 24);
	}

	static private function swap16($x){
		return (($x & 0x00ff) << 8) | (($x & 0xff00) >> 8);
	}

	static private function detectFormat($src){

		if(is_string($src)){

			return self::FMT_STRING;

		}else if(is_array($src)){
			
			$len = count($src);
			
			if($len == 1 || ($len % 2) == 0){
				
				return $len;

			}else{

				return (-1);

			}

		}else{

			return self::FMT_BINARY;

		}

	}

	static public function generate($type, $fmt = self::FMT_STRING, $node = "", $ns = ""){
		
		$func = self::$m_generate[$type];

		if(!isset($func)){

			return null;

		}
		
		$conv = self::$m_convert[self::FMT_FIELD][$fmt];
		$uuid = self::$func($ns, $node);

		return self::$conv($uuid);

	}

	static public function convert($uuid, $from, $to){

		$conv = self::$m_convert[$from][$to];
		
		if(!isset($conv)){

			return ($uuid);

		}

		return (self::$conv($uuid));

	}

	static private function generateRandom($ns, $node){

		$uuid = self::$m_uuid_field;

		$uuid['time_hi'] = (4 << 12) | (mt_rand(0, 0x1000));
		$uuid['clock_seq_hi'] = (1 << 7) | mt_rand(0, 128);
		$uuid['time_low'] = mt_rand(0, 0xffff) + (mt_rand(0, 0xffff) << 16);
		$uuid['time_mid'] = mt_rand(0, 0xffff);
		$uuid['clock_seq_low'] = mt_rand(0, 255);

		for ($i = 0; $i < 6; $i++){

			$uuid['node'][$i] = mt_rand(0, 255);

		}

		return ($uuid);

	}

	static private function generateName($ns, $node, $hash, $version){

		$ns_fmt = self::detectFormat($ns);
		$field = self::convert($ns, $ns_fmt, self::FMT_FIELD);

		$field['time_low'] = self::swap32($field['time_low']);
		$field['time_mid'] = self::swap16($field['time_mid']);
		$field['time_hi'] = self::swap16($field['time_hi']);

		$raw = self::convert($field, self::FMT_FIELD, self::FMT_BINARY);
		$raw .= $node;

		$val = $hash($raw, true);	
		$tmp = unpack('C16', $val);
		$byte = [];
		foreach (array_keys($tmp) as $key)
			$byte[$key - 1] = $tmp[$key];

		$field = self::conv_byte2field($byte);

		$field['time_low'] = self::swap32($field['time_low']);
		$field['time_mid'] = self::swap16($field['time_mid']);
		$field['time_hi'] = self::swap16($field['time_hi']);

		/* Apply version and constants */
		$field['clock_seq_hi'] &= 0x3f;
		$field['clock_seq_hi'] |= (1 << 7);
		$field['time_hi'] &= 0x0fff;
		$field['time_hi'] |= ($version << 12);

		return ($field);	

	}

	static private function generateNameMD5($ns, $node){

		return self::generateName($ns, $node, "md5", self::UUID_NAME_MD5);

	}

	static private function generateNameSHA1($ns, $node){

		return self::generateName($ns, $node, "sha1", self::UUID_NAME_SHA1);

	}

	static private function generateTime($ns, $node){
		
		$uuid = self::$m_uuid_field;

		$tp = gettimeofday();
		$time = ($tp['sec'] * 10000000) + ($tp['usec'] * 10) + 0x01B21DD213814000;

		$uuid['time_low'] = $time & 0xffffffff;
		$high = intval($time / 0xffffffff);
		$uuid['time_mid'] = $high & 0xffff;
		$uuid['time_hi'] = (($high >> 16) & 0xfff) | (self::UUID_TIME << 12);
		
		$uuid['clock_seq_hi'] = 0x80 | mt_rand(0, 64);
		$uuid['clock_seq_low'] = mt_rand(0, 255);

		for($i = 0; $i < 6; $i++){

			$uuid['node'][$i] = ord(substr($node, $i, 1));

		}

		return ($uuid);

	}

	static private function conv_field2byte($src){

		$uuid[0] = ($src['time_low'] & 0xff000000) >> 24;
		$uuid[1] = ($src['time_low'] & 0x00ff0000) >> 16;
		$uuid[2] = ($src['time_low'] & 0x0000ff00) >> 8;
		$uuid[3] = ($src['time_low'] & 0x000000ff);
		$uuid[4] = ($src['time_mid'] & 0xff00) >> 8;
		$uuid[5] = ($src['time_mid'] & 0x00ff);
		$uuid[6] = ($src['time_hi'] & 0xff00) >> 8;
		$uuid[7] = ($src['time_hi'] & 0x00ff);
		$uuid[8] = $src['clock_seq_hi'];
		$uuid[9] = $src['clock_seq_low'];

		for($i = 0; $i < 6; $i++){

			$uuid[10+$i] = $src['node'][$i];

		}

		return($uuid);

	}

	static private function conv_field2string($src){

		$str = sprintf(
			'%08x-%04x-%04x-%02x%02x-%02x%02x%02x%02x%02x%02x',
			($src['time_low']), ($src['time_mid']), ($src['time_hi']),
			$src['clock_seq_hi'], $src['clock_seq_low'],
			$src['node'][0], $src['node'][1], $src['node'][2],
			$src['node'][3], $src['node'][4], $src['node'][5]);

		return ($str);

	}

	static private function conv_field2binary($src){

		$byte = self::conv_field2byte($src);
		return self::conv_byte2binary($byte);

	}

	static private function conv_byte2field($uuid){

		$field = self::$m_uuid_field;
		$field['time_low'] = ($uuid[0] << 24) | ($uuid[1] << 16) |
			($uuid[2] << 8) | $uuid[3];
		$field['time_mid'] = ($uuid[4] << 8) | $uuid[5];
		$field['time_hi'] = ($uuid[6] << 8) | $uuid[7];
		$field['clock_seq_hi'] = $uuid[8];
		$field['clock_seq_low'] = $uuid[9];

		for ($i = 0; $i < 6; $i++){

			$field['node'][$i] = $uuid[10+$i];

		}

		return ($field);

	}

	static public function conv_byte2string($src){

		$field = self::conv_byte2field($src);
		return self::conv_field2string($field);

	}

	static private function conv_byte2binary($src){

		$raw = pack('C16', $src[0], $src[1], $src[2], $src[3],
			$src[4], $src[5], $src[6], $src[7], $src[8], $src[9],
			$src[10], $src[11], $src[12], $src[13], $src[14], $src[15]);
		
		return ($raw);

	}

	static private function conv_string2field($src){

		$parts = sscanf($src, '%x-%x-%x-%x-%02x%02x%02x%02x%02x%02x');
		$field = self::$m_uuid_field;
		$field['time_low'] = ($parts[0]);
		$field['time_mid'] = ($parts[1]);
		$field['time_hi'] = ($parts[2]);
		$field['clock_seq_hi'] = ($parts[3] & 0xff00) >> 8;
		$field['clock_seq_low'] = $parts[3] & 0x00ff;

		for ($i = 0; $i < 6; $i++){

			$field['node'][$i] = $parts[4+$i];

		}

		return ($field);

	}

	static private function conv_string2byte($src){

		$field = self::conv_string2field($src);
		return self::conv_field2byte($field);

	}

	static private function conv_string2binary($src){

		$byte = self::conv_string2byte($src);
		return self::conv_byte2binary($byte);
		
	}

}
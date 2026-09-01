<?php

namespace Psf\Utils;

use \Aws\S3\S3Client;

class CloudStorage{
	public static function connect(){
		$configAws = \PSF::getConfig()->aws;

		if(strtoupper($configAws['provider']) == 'R2'){
			$credentials = new \Aws\Credentials\Credentials($configAws['access_key_id'], $configAws['access_key_secret']);

			$options = [
			    'region' => 'auto',
			    'endpoint' => "https://" . $configAws['account_id'] . ".r2.cloudflarestorage.com",
			    'version' => 'latest',
			    'credentials' => $credentials
			];

			try {
				$s3Client = new S3Client($options);
			} catch (Exception $e) {
				return false;
			}
		}

		return $s3Client;
	} 

	public static function putObject(S3Client $connect, string $filename, $atualpath, string $acl = 'public-read', string $filetype = 'binary'){
		$configAws = \PSF::getConfig()->aws;

		if(strtoupper($configAws['provider']) == 'R2'){
			try {
			    $put = $connect->putObject([
			        'Bucket' 		=> $configAws['bucket'],
			        'Key'    		=> $filename,
			        'Body'   		=> $filetype == 'path' ? fopen($atualpath, 'r') : $atualpath,
			        'ACL'    		=> $acl,
			        'ContentType' 	=> mime_content_type($atualpath)
			    ]);

			    return $put;
			} catch (\Aws\S3\Exception\S3Exception $e) {
			    return false;
			}
		}
	}

	public static function deleteObject(\Aws\S3\S3Client $connect, string $filename){
		$configAws = \PSF::getConfig()->aws;

		if(strtoupper($configAws['provider']) == 'R2'){
			try {
		   		$delete = $connect->deleteObject(array(
		        	'Bucket' => $configAws['bucket'],
		        	'Key'    => $filename
		        ));
		        return true;
		    } catch (\Aws\S3\Exception\S3Exception $e) {
		    	echo $e->getMessage();
		    	return false;
		    }
		}
	}

	public static function listObjects(S3Client $connect, ?string $continuationToken = NULL): array{
		$configAws = \PSF::getConfig()->aws;

		if(strtoupper($configAws['provider']) != 'R2'){
			return ['keys' => [], 'nextContinuationToken' => NULL, 'isTruncated' => false];
		}

		try {
			$params = ['Bucket' => $configAws['bucket']];
			if($continuationToken !== NULL){
				$params['ContinuationToken'] = $continuationToken;
			}

			$result = $connect->listObjectsV2($params);

			$keys = [];
			foreach(($result['Contents'] ?? []) as $object){
				$keys[] = ['key' => $object['Key']];
			}

			return [
				'keys' => $keys,
				'nextContinuationToken' => $result['NextContinuationToken'] ?? NULL,
				'isTruncated' => (bool) ($result['IsTruncated'] ?? false),
			];
		} catch (\Aws\S3\Exception\S3Exception $e) {
			return ['keys' => [], 'nextContinuationToken' => NULL, 'isTruncated' => false];
		}
	}
}
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
				return new S3Client($options);
			} catch (\Throwable $e) {
				return false;
			}
		}

		// Provider não suportado
		return false;
	}

	public static function putObject(S3Client $connect, string $filename, $atualpath, string $acl = 'public-read', string $filetype = 'binary'){
		$configAws = \PSF::getConfig()->aws;

		if(strtoupper($configAws['provider']) == 'R2'){
			$isPath = $filetype == 'path';
			$stream = $isPath ? @fopen($atualpath, 'r') : null;

			if($isPath && $stream === false){
				return false;
			}

			try {
			    // `mime_content_type` espera um caminho; para conteúdo em memória usa o finfo sobre o buffer
			    $contentType = $isPath
			    	? mime_content_type($atualpath)
			    	: (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) $atualpath);

			    $put = $connect->putObject([
			        'Bucket' 		=> $configAws['bucket'],
			        'Key'    		=> $filename,
			        'Body'   		=> $isPath ? $stream : $atualpath,
			        'ACL'    		=> $acl,
			        'ContentType' 	=> $contentType ?: 'application/octet-stream'
			    ]);

			    return $put;
			} catch (\Aws\S3\Exception\S3Exception $e) {
			    return false;
			} finally {
				if(is_resource($stream)){
					fclose($stream);
				}
			}
		}

		return false;
	}

	public static function getObject(S3Client $connect, string $filename, string $saveTo){
		$configAws = \PSF::getConfig()->aws;

		if(strtoupper($configAws['provider']) == 'R2'){
			try {
				$connect->getObject([
					'Bucket' 	=> $configAws['bucket'],
					'Key'    	=> $filename,
					'SaveAs' 	=> $saveTo,
				]);

				return $saveTo;
			} catch (\Aws\S3\Exception\S3Exception $e) {
				return false;
			}
		}

		return false;
	}

	public static function listObjects(S3Client $connect, ?string $continuationToken = NULL, int $maxKeys = 1000): array{
		$configAws = \PSF::getConfig()->aws;

		if(strtoupper($configAws['provider']) != 'R2'){
			return ['keys' => [], 'nextContinuationToken' => NULL, 'isTruncated' => false];
		}

		$params = [
			'Bucket'  => $configAws['bucket'],
			'MaxKeys' => $maxKeys,
		];

		if($continuationToken !== NULL){
			$params['ContinuationToken'] = $continuationToken;
		}

		try {
			$result = $connect->listObjectsV2($params);
		} catch (\Aws\S3\Exception\S3Exception $e) {
			return ['keys' => [], 'nextContinuationToken' => NULL, 'isTruncated' => false];
		}

		$keys = [];
		foreach(($result['Contents'] ?? []) as $object){
			$keys[] = [
				'key'          => $object['Key'],
				'size'         => (int) $object['Size'],
				'lastModified' => $object['LastModified'],
			];
		}

		return [
			'keys'                  => $keys,
			'nextContinuationToken' => $result['NextContinuationToken'] ?? NULL,
			'isTruncated'           => (bool) ($result['IsTruncated'] ?? false),
		];
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
		    	// Sem echo: a mensagem do SDK quebraria o JSON da resposta e vazaria detalhes
		    	return false;
		    }
		}

		return false;
	}
}

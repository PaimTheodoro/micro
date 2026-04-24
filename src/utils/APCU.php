<?php

namespace Psf\Utils;

class APCU{
    public static function getStorage() : bool|array{
        if(extension_loaded('apcu')){
            $cacheInfo = apcu_cache_info();

            if($cacheInfo && isset($cacheInfo['cache_list'])){
                return array_map(function($item){
                    return [$item['info'] => [
                        'ttl'       => $item['ttl'],
                        'hits'      => $item['num_hits'],
                        'created'   => $item['creation_time'],
                        'size'      => $item['mem_size'],
                    ]];
                }, $cacheInfo['cache_list']);
            }
        }

        return FALSE;
    }

    public static function clear(null|string $initWith = null) : bool|int{
        if(extension_loaded('apcu')){
            $deleteds = 0;

            if(!empty($initWith)){
                $allKeys = apcu_cache_info()['cache_list'];

                foreach ($allKeys as $cacheEntry) {
                    if (str_starts_with($cacheEntry['info'], $initWith)) {
                        apcu_delete($cacheEntry['info']);
                        $deleteds++;
                    }
                }
            }
        }

        return $deleteds ?? FALSE;
    }
}
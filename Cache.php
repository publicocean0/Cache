<?php

/**
 * Cache  utility
 * @author Cristian Lorenzetto <opensource.publicocean0@gmail.com>
 */


    class Cache {
    const CONF_FILE='cache.conf';
    protected $config;
    protected $region;
    protected $timetolive;

    public function __construct($region='',$timetolive=0,$config=null){
     $this->region=$region;

     if ($config==null) {
       $file=__DIR__.'/'.self::CONF_FILE;
       if (file_exists($file)){
         $s=file_get_contents($file);
         $this->config=json_decode($s,true);
         if ($this->config==null) throw new Exception('cache.conf contains errors');
       }
       if (!isset($this->config['type'])) {
         if (class_exists('Memcached') && isset($this->config['memcached'])) {
          $type='MEM_CACHE';
          $this->memcached_init();
         }
         else if (function_exists('zend_shm_cache_fetch')) $type='ZEND_CACHE';
         else if (function_exists('apc_fetch')) $type='APC_CACHE';
         else if (function_exists('xcache_get')) $type='X_CACHE';
         else $type='FILE_CACHE';
       } else {       
         if ($this->config['type']=='MEM_CACHE')    $this->memcached_init();            
         }
       
     }
     if ($timetolive!=null) $this->timetolive=$timetolive;
     else if ($region !='' && isset($this->config['regions'][$region])){
      if (isset($this->config['regions'][$region]['timetolive'])) $this->timetolive=$this->config['regions'][$region]['timetolive'];     
     }  else      $this->timetolive=0;
     $this->config['type']=$type;
    }
    
    
    private function memcached_init(){
      if (is_array($this->config['memcached']['servers'])) {
             $memcached=new Memcached();
             $this->config['memcached']['obj']=$memcached;
             foreach($this->config['memcached']['servers'] as $server) $memcached->addServer($server['host'],$server['port'],(isset($this->config['memcached']['persistent'])?$this->config['memcached']:false));
       }  else trigger_error('memcached_servers parameter is not correct');
    }

    public function __get($name){
      switch($this->config['type']){
        case 'ZEND_CACHE': return zend_shm_fetch($this->region.$name);
        case 'APC_CACHE': return  apc_fetch($this->region.$name);
        case 'X_CACHE': return  xcache_get($this->region.$name);
        case 'FILE_CACHE':
         $file= __DIR__.'/data/'. $this->region.base64_encode($name);
         if (file_exists($file)) {          
           if ($this->timetolive>0 && (filemtime($file)+$this->timetolive < time())) {
            $wait=true;
            $fp=fopen($file,'w');
            flock($fp, LOCK_EX,$wait);
            @unlink($file);
            flock($fp, LOCK_UN); 
            fclose($fp);
            return null;
            }
           else  {
            $wait=true;
            $fp=fopen($file,'r');
            flock($fp, LOCK_SH,$wait);
            $result=unserialize(@file_get_contents($file));
            flock($fp, LOCK_UN);
            fclose($fp);
            return $result;
            }
         }  else return null;
        case 'MEM_CACHE': return  $this->config['memcached']['obj']->get($this->region.$name);
      }
    }


   public function __set($name,$value){
      if (substr($name,0,1)=='_') throw new Exception('cache key must not start with _ character ');
      $key=$this->region.$name;
      switch($this->config['type']){
        case 'ZEND_CACHE': return zend_shm_store($key,$value,$this->timetolive); 
        case 'APC_CACHE': return  apc_store($key,$value,$this->timetolive);
        case 'X_CACHE': return  xcache_set($key,$value,$this->timetolive);
        case 'FILE_CACHE': {
        $wait=true;
        $file= __DIR__.'/data/'.$this->region.base64_encode($name);
        $fp=fopen($file,'w');
        flock($fp, LOCK_EX,$wait);
        fwrite($fp,serialize($value));
        flock($fp, LOCK_UN); 
        fclose($fp);
        return $result;
        }
        case 'MEM_CACHE': return  $this->config['memcached']['obj']->set($key,$value,0,$this->timetolive);
      }
    }
                                              

    public function __isset($name){  
      switch($this->config['type']){
        case 'ZEND_CACHE': return zend_shm_cache_exists($this->region.$name);
        case 'APC_CACHE': return  apc_exists($this->region.$name);
        case 'X_CACHE': return  xcache_isset($this->region.$name);
        case 'FILE_CACHE':  
         $file= __DIR__.'/data/' .$this->region.base64_encode($name);
         if (file_exists($file)) {    
            if ($this->timetolive>0 && (filemtime($file)+$this->timetolive < time())) {          
              $wait=true;              
              $fp=fopen($file,'w');
              flock($fp, LOCK_EX,$wait);
              @unlink($file);
              flock($fp, LOCK_UN);  
              fclose($fp);
              return false;
            } else return true;
         } else return false;
        case 'MEM_CACHE': return  $this->config['memcached']['obj']->get($this->region.$name)!=null;
      }
    }
    
    
    
     public function __unset($name){
      switch($this->config['type']){
        case 'ZEND_CACHE': return zend_shm_cache_delete($this->region.$name);
        case 'APC_CACHE': return  apc_delete($this->region.$name);
        case 'FILE_CACHE': 
         $file= __DIR__.'/data/'. $this->region.base64_encode($name);
         $wait=true;
         $fp=fopen($file,'w');
         flock($fp, LOCK_EX,$wait);
         $result=@unlink($file);
         flock($file, LOCK_UN);
         fclose($file);
        case 'MEM_CACHE': return  $this->config['memcached']['obj']->delete($this->region.$name);
      }
    }


       public function removeAll($subregion=''){
       switch($this->config['type']){
        case 'ZEND_CACHE':
        foreach (new KeyListIterator('user', '/^'.$this->region.$subregion.'/') as $counter) {
              apc_delete($counter['key']);
        }
        break;
        case 'APC_CACHE':
          foreach (new APCIterator('user', '/^'.$this->region.$subregion.'/') as $counter) {
              apc_delete($counter['key']);
          }
        break;
        case 'FILE_CACHE':
         $filep= __DIR__.'/data/'. $this->region.base64_encode($subregion);
         $wait=true;
         foreach (glob($filep.".*") as $file) {
           $fp=fopen($file,'w');
           flock($fp, LOCK_EX,$wait);
           @unlink($file);
           flock($file, LOCK_UN);
           fclose($file);
         }
        break;
        case 'MEM_CACHE':
          $zone=$this->region.$subregion;
          $zonelen=strlen($zone);
          $mem=$this->config['memcached']['obj'];
          $items=$mem->getExtendedStats ( "items" );
          foreach($items as $keyserver=>$sitems){
            $titems = $sitems[ 'items' ];
            for ( $i =0, $len =count ( $titems ); $i < $len ; $i ++){
              $number = $titems [ $i ][ 'number' ];
              $str = $mem->getExtendedStats ( "cachedump" , $number , 0);
              $line = $str[ $keyserver ];
               if (is_array ( $line ) && count ( $line )> 0) {
                  foreach ( $line as $key){
                            if (substr($key,0,$zonelen)==$zone) $mem->delete($key);
                  }
               }
            }
          }
          break;
        }
        // end switch
       }


      }
    
    
    ?>
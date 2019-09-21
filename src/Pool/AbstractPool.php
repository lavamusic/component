<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/6/22
 * Time: 下午1:21
 */

namespace EasySwoole\Component\Pool;


use EasySwoole\Component\Context\ContextManager;
use EasySwoole\Component\Pool\Exception\PoolEmpty;
use EasySwoole\Component\Pool\Exception\PoolException;
use EasySwoole\Component\Pool\Exception\PoolObjectNumError;
use EasySwoole\Utility\Random;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Swoole\Coroutine;

/**
 * Class AbstractPool
 * @package EasySwoole\Component\Pool
 * @method invoker(callable $call,float $timeout = null);
 * @method defer($timeout = null);
 */
abstract class AbstractPool
{
    private $createdNum = 0;
    private $poolChannel;
    private $objHash = [];
    private $conf;
    private $timerId;
    private $destroy = false;

    /*
     * 如果成功创建了,请返回对应的obj
     */
    abstract protected function createObject();

    public function __construct(PoolConf $conf)
    {
        if ($conf->getMinObjectNum() >= $conf->getMaxObjectNum()) {
            $class = static::class;
            throw new PoolObjectNumError("pool max num is small than min num for {$class} error");
        }
        $this->conf = $conf;
        $this->poolChannel = new Channel($conf->getMaxObjectNum() + 8);
        if ($conf->getIntervalCheckTime() > 0) {
            $this->timerId = Timer::tick($conf->getIntervalCheckTime(), [$this, 'intervalCheck']);
        }
    }

    /*
     * 回收一个对象
     */
    public function recycleObj($obj): bool
    {
        if($this->destroy){
            $this->unsetObj($obj);
            return true;
        }
        /*
         * 仅仅允许归属于本pool且不在pool内的对象进行回收
         */
        if($this->isPoolObject($obj) && (!$this->isInPool($obj))){
            $hash = $obj->__objHash;
            //标记为在pool内
            $this->objHash[$hash] = true;
            if($obj instanceof PoolObjectInterface){
                try{
                    $obj->objectRestore();
                }catch (\Throwable $throwable){
                    //重新标记为非在pool状态,允许进行unset
                    $this->objHash[$hash] = false;
                    $this->unsetObj($obj);
                    throw $throwable;
                }
            }
            $this->poolChannel->push($obj);
            return true;
        }else{
            return false;
        }
    }

    /*
     * tryTimes为出现异常尝试次数
     */
    public function getObj(float $timeout = null, int $tryTimes = 3)
    {
        if($this->destroy){
            return null;
        }
        if($timeout === null){
            $timeout = $this->getConfig()->getGetObjectTimeout();
        }
        $object = null;
        if($this->poolChannel->isEmpty()){
            try{
                $this->initObject();
            }catch (\Throwable $throwable){
                if($tryTimes <= 0){
                    throw $throwable;
                }else{
                    $tryTimes--;
                    return $this->getObj($timeout,$tryTimes);
                }
            }
        }
        $object = $this->poolChannel->pop($timeout);
        if(is_object($object)){
            if($object instanceof PoolObjectInterface){
                try{
                    if($object->beforeUse() === false){
                        $this->unsetObj($object);
                        if($tryTimes <= 0){
                            return null;
                        }else{
                            $tryTimes--;
                            return $this->getObj($timeout,$tryTimes);
                        }
                    }
                }catch (\Throwable $throwable){
                    $this->unsetObj($object);
                    if($tryTimes <= 0){
                        throw $throwable;
                    }else{
                        $tryTimes--;
                        return $this->getObj($timeout,$tryTimes);
                    }
                }
            }
            $hash = $object->__objHash;
            //标记该对象已经被使用，不在pool中
            $this->objHash[$hash] = false;
            $object->__lastUseTime = time();
            return $object;
        }else{
            return null;
        }
    }

    /*
     * 彻底释放一个对象
     */
    public function unsetObj($obj): bool
    {
        if($this->isPoolObject($obj) && (!$this->isInPool($obj))){
            $hash = $obj->__objHash;
            unset($this->objHash[$hash]);
            if($obj instanceof PoolObjectInterface){
                try{
                    $obj->gc();
                }catch (\Throwable $throwable){
                    throw $throwable;
                }finally{
                    $this->createdNum--;
                }
            }else{
                $this->createdNum--;
            }
            return true;
        }else{
            return false;
        }
    }

    /*
     * 超过$idleTime未出队使用的，将会被回收。
     */
    public function idleCheck(int $idleTime)
    {
        $list = [];
        while (!$this->poolChannel->isEmpty()){
            $item = $this->poolChannel->pop(0.01);
            if(time() - $item->__lastUseTime > $idleTime){
                //标记为不在队列内，允许进行gc回收
                $hash = $item->__objHash;
                $this->objHash[$hash] = false;
                $this->unsetObj($item);
            }else{
                $list[] = $item;
            }
        }
        foreach ($list as $item){
            $this->poolChannel->push($item);
        }
    }

    /*
     * 允许外部调用
     */
    public function intervalCheck()
    {
        $this->idleCheck($this->getConfig()->getMaxIdleTime());
        $this->keepMin($this->getConfig()->getMinObjectNum());
    }

    public function keepMin(?int $num = null): int
    {
        if($this->createdNum < $num){
            $left = $num - $this->createdNum;
            while ($left > 0 ){
                $this->initObject();
                $left--;
            }
        }
        return $this->createdNum;
    }

    /*
     * 用以解决冷启动问题,其实是是keepMin别名
    */
    public function preLoad(?int $num = null): int
    {
        return $this->keepMin($num);
    }



    public function getConfig():PoolConf
    {
        return $this->conf;
    }

    public function status()
    {
        return [
            'created' => $this->createdNum,
            'inuse' => $this->createdNum - $this->poolChannel->stats()['queue_num'],
            'max' => $this->getConfig()->getMaxObjectNum(),
            'min' => $this->getConfig()->getMinObjectNum()
        ];
    }

    private function initObject():bool
    {
        $obj = null;
        $this->createdNum++;
        if($this->createdNum > $this->getConfig()->getMaxObjectNum()){
            $this->createdNum--;
            return false;
        }
        try{
            $obj = $this->createObject();
            if(is_object($obj)){
                $hash = Random::character(12);
                $this->objHash[$hash] = true;
                $obj->__objHash = $hash;
                $obj->__lastUseTime = time();
                $this->poolChannel->push($obj);
                return true;
            }else{
                $this->createdNum--;
            }
        }catch (\Throwable $throwable){
            $this->createdNum--;
            throw $throwable;
        }
        return false;
    }

    public function isPoolObject($obj):bool
    {
        if(isset($obj->__objHash)){
            return isset($this->objHash[$obj->__objHash]);
        }else{
            return false;
        }
    }

    public function isInPool($obj):bool
    {
        if($this->isPoolObject($obj)){
            return $this->objHash[$obj->__objHash];
        }else{
            return false;
        }
    }

    function destroyPool()
    {
        $this->destroy = true;
        if($this->timerId && Timer::exists($this->timerId)){
            Timer::clear($this->timerId);
            $this->timerId = null;
        }
        while (!$this->poolChannel->isEmpty()){
            $item = $this->poolChannel->pop(0.01);
            $this->unsetObj($item);
        }
    }

    public static function __callStatic($name, $arguments)
    {
        switch ($name){
            case 'invoke':{
                $call = $arguments[0];
                $timeout = $arguments[1];
                $pool = PoolManager::getInstance()->getPool(static::class);
                if($pool instanceof AbstractPool){
                    $obj = $pool->getObj($timeout);
                    if($obj){
                        try{
                            $ret = call_user_func($call,$obj);
                            return $ret;
                        }catch (\Throwable $throwable){
                            throw $throwable;
                        }finally{
                            $pool->recycleObj($obj);
                        }
                    }else{
                        throw new PoolEmpty(static::class." pool is empty");
                    }
                }else{
                    throw new PoolException(static::class." convert to pool error");
                }
                break;
            }
            case 'defer':{
                $timeout = $arguments[0];
                $key = md5(static::class);
                $obj = ContextManager::getInstance()->get($key);
                if($obj){
                    return $obj;
                }else{
                    $pool = PoolManager::getInstance()->getPool(static::class);
                    if($pool instanceof AbstractPool){
                        $obj = $pool->getObj($timeout);
                        if($obj){
                            Coroutine::defer(function ()use($pool,$obj){
                                $pool->recycleObj($obj);
                            });
                            ContextManager::getInstance()->set($key,$obj);
                            return $obj;
                        }else{
                            throw new PoolEmpty(static::class." pool is empty");
                        }
                    }else{
                        throw new PoolException(static::class." convert to pool error");
                    }
                }
                break;
            }
            default:{
                throw new PoolException(" function {$name} not exist in class ".static::class);
            }
        }
    }

    public function __call($name, $arguments)
    {
        switch ($name){
            case 'invoke':{
                $call = $arguments[0];
                $timeout = $arguments[1];
                $obj = $this->getObj($timeout);
                if($obj){
                    try{
                        $ret = call_user_func($call,$obj);
                        return $ret;
                    }catch (\Throwable $throwable){
                        throw $throwable;
                    }finally{
                        $this->recycleObj($obj);
                    }
                }else{
                    throw new PoolEmpty(static::class." pool is empty");
                }
                break;
            }
            case 'defer':{
                $timeout = $arguments[0];
                $key = spl_object_hash($this);
                $obj = ContextManager::getInstance()->get($key);
                if($obj){
                    return $obj;
                }else{
                    $obj = $this->getObj($timeout);
                    if($obj){
                        Coroutine::defer(function ()use($obj){
                            $this->recycleObj($obj);
                        });
                        ContextManager::getInstance()->set($key,$obj);
                        return $obj;
                    }else{
                        throw new PoolEmpty(static::class." pool is empty");
                    }
                }
                break;
            }
            default:{
                throw new PoolException(" function {$name} not exist in class ".static::class);
            }
        }
    }
}

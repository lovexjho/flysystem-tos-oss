<?php

namespace Lovexjho\FlysystemTosOss;


use Hyperf\Filesystem\Contract\AdapterFactoryInterface;

class TosAdapterFactory implements AdapterFactoryInterface
{

    public function make(array $options)
    {
        return new TosAdapter($options);
    }
}
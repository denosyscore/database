<?php

declare(strict_types=1);

namespace CFXP\Core\Database\Schema;

/**
 * Types of indexes supported.
 */
enum IndexType: string
{
    case Primary = 'primary';
    case Unique = 'unique';
    case Index = 'index';
    case Fulltext = 'fulltext';
    case Spatial = 'spatial';
}

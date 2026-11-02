<?php

namespace App\Models;

defined('BASEPATH') or exit('No direct script access allowed');

use System\Models;

class Test extends Models
{
    public function check()
    {
        echo 'test model';
    }

}

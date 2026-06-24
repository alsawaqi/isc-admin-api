<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductQuestionAnswer extends Model
{
    use SoftDeletes;

    protected $table = 'Product_Question_Answers_T';
    protected $guarded = [];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostcodeImport extends Model
{
    use HasFactory;
    protected $table = 'postcode_import';
    protected $fillable = ['md5_hash', 'size', 'updated_at'];
}

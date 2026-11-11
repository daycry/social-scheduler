<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Suscriptor del formulario de email de un SmartLink (T-20). Solo el email y el momento; sin IP ni agente. */
class SmartLinkSubscriber extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['smart_link_id', 'smart_link_block_id', 'email'];
}

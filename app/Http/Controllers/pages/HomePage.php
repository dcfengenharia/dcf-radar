<?php

namespace App\Http\Controllers\pages;

use App\Http\Controllers\Controller;

class HomePage extends Controller
{
    public function index()
    {
        return view('content.pages.pages-home');
    }
}

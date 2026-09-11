<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function index()
    {
      return view('site.index');
    }

    public function solucao()
    {
      return view('site.solucao');
    }

    public function plataforma()
    {
      return view('site.plataforma');
    }

    public function diagnostico()
    {
      return view('site.diagnostico');
    }

    public function conteudos()
    {
      return view('site.conteudos');
    }

    public function tutoriais()
    {
      return view('site.tutoriais');
    }

    public function politica_privacidade()
    {
      return view('site.politica_privacidade');
    }

    public function termos_uso()
    {
      return view('site.termos_uso');
    }


    public function politica_cookies()
    {
      return view('site.politica_cookies');
    }



}

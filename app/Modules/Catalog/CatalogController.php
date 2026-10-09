<?php

namespace App\Modules\Catalog;

use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogController
{
    public function __construct(private CatalogService $catalog) {}

    public function index()
    {
        return Api::ok('Productos', $this->catalog->list());
    }

    public function show(string $id)
    {
        return Api::ok('Producto', $this->catalog->get($id));
    }

    public function store(Request $r)
    {
        return Api::ok('Producto guardado', $this->catalog->upsert($r->all()));
    }

    public function categories()
    {
        return Api::ok('Categorías', DB::table('categories')->orderBy('name')->pluck('name'));
    }

    public function brands()
    {
        return Api::ok('Marcas', DB::table('brands')->orderBy('name')->pluck('name'));
    }
}

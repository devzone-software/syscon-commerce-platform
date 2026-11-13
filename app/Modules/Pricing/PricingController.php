<?php

namespace App\Modules\Pricing;

use App\Support\Api;
use Illuminate\Http\Request;

/** Precios de referencia (catalog:write): sugerencias, empresas, enlaces y márgenes. */
class PricingController
{
    public function __construct(private PricingService $pricing) {}

    public function suggestions()
    {
        return Api::ok('Precios sugeridos', $this->pricing->suggestions());
    }

    public function approve(Request $r, string $productId)
    {
        return Api::ok('Precio actualizado', $this->pricing->approve($productId, $r->all()));
    }

    public function dismiss(string $productId)
    {
        $this->pricing->dismiss($productId);

        return Api::ok('Sugerencia ignorada');
    }

    public function sources()
    {
        return Api::ok('Empresas de referencia', $this->pricing->sources());
    }

    public function storeSource(Request $r)
    {
        return Api::ok('Empresa registrada', $this->pricing->saveSource($r->all()), 201);
    }

    public function updateSource(Request $r, string $id)
    {
        return Api::ok('Empresa actualizada', $this->pricing->saveSource($r->all(), $id));
    }

    public function references()
    {
        return Api::ok('Enlaces de referencia', $this->pricing->references());
    }

    public function storeReference(Request $r)
    {
        return Api::ok('Enlace guardado', $this->pricing->saveReference($r->all()), 201);
    }

    public function manualPrice(Request $r, string $id)
    {
        return Api::ok('Precio registrado', $this->pricing->manualPrice($id, $r->all()));
    }

    public function refresh(string $id)
    {
        return Api::ok('Precio consultado', $this->pricing->refresh($id));
    }

    public function deleteReference(string $id)
    {
        $this->pricing->deleteReference($id);

        return Api::ok('Enlace eliminado');
    }

    public function margins()
    {
        return Api::ok('Márgenes por categoría', $this->pricing->margins());
    }

    public function saveMargin(Request $r, string $categoryId)
    {
        return Api::ok('Margen guardado', $this->pricing->saveMargin($categoryId, $r->all()));
    }
}

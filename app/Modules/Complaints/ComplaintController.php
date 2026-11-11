<?php

namespace App\Modules\Complaints;

use App\Support\Api;
use Illuminate\Http\Request;

class ComplaintController
{
    public function __construct(private ComplaintService $complaints) {}

    /** Público: cualquier consumidor puede registrar una hoja, con o sin cuenta. */
    public function store(Request $r)
    {
        return Api::ok('Hoja de reclamación registrada', $this->complaints->create($r->all()), 201);
    }

    public function index(Request $r)
    {
        $status = $r->query('status');

        return Api::ok('Libro de Reclamaciones', $this->complaints->list(in_array($status, ['PENDING', 'ANSWERED'], true) ? $status : null));
    }

    public function show(string $id)
    {
        return Api::ok('Hoja de reclamación', $this->complaints->get($id));
    }

    public function respond(Request $r, string $id)
    {
        return Api::ok('Respuesta registrada', $this->complaints->respond($id, $r->all(), $r->user()?->id));
    }
}

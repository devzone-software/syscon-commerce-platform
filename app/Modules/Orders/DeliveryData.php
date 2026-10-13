<?php

namespace App\Modules\Orders;

use Illuminate\Validation\Validator;

/**
 * Modalidad de entrega del pedido. El costo lo decide siempre el servidor según la configuración
 * (commerce.delivery); el cliente solo elige la modalidad y envía sus datos de contacto.
 */
class DeliveryData
{
    public const PICKUP = 'PICKUP';

    public const DELIVERY = 'DELIVERY';

    /** Tarifa de envío configurada como decimal "0.00", o null si el envío no está habilitado. */
    public static function fee(): ?string
    {
        $fee = trim((string) config('commerce.delivery.fee'));

        return preg_match('/^\d{1,8}(\.\d{1,2})?$/', $fee) ? bcadd($fee, '0', 2) : null;
    }

    /** Opciones visibles en el checkout; el costo mostrado es el mismo que se cobra. */
    public static function options(): array
    {
        $fee = self::fee();

        return [
            'pickup' => ['enabled' => true, 'cost' => '0.00', 'address' => self::text('pickup_address'), 'hours' => self::text('pickup_hours')],
            'delivery' => ['enabled' => $fee !== null, 'cost' => $fee, 'areas' => self::text('areas')],
        ];
    }

    public static function cost(string $method): string
    {
        return $method === self::DELIVERY ? (self::fee() ?? '0.00') : '0.00';
    }

    public static function rules(string $prefix): array
    {
        return [
            $prefix.'method' => ['required', 'in:'.self::PICKUP.','.self::DELIVERY],
            $prefix.'contactName' => ['required', 'string', 'min:2', 'max:120'],
            $prefix.'contactPhone' => ['required', 'regex:/^9\d{8}$/'],
            $prefix.'address' => ['nullable', 'required_if:'.$prefix.'method,'.self::DELIVERY, 'string', 'min:5', 'max:255'],
            $prefix.'district' => ['nullable', 'required_if:'.$prefix.'method,'.self::DELIVERY, 'string', 'min:2', 'max:80'],
            $prefix.'reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function check(Validator $validator, array $data, string $prefix): void
    {
        if (($data['method'] ?? null) === self::DELIVERY && self::fee() === null) {
            $validator->errors()->add($prefix.'method', 'El envío a domicilio no está disponible por ahora.');
        }
    }

    /** Quita espacios sobrantes y descarta los datos de dirección cuando se retira en tienda. */
    public static function normalize(array $data): array
    {
        foreach (['contactName', 'contactPhone', 'address', 'district', 'reference'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = preg_replace('/\s+/u', ' ', trim($data[$key]));
                if ($data[$key] === '') {
                    $data[$key] = null;
                }
            }
        }
        if (isset($data['contactPhone'])) {
            $data['contactPhone'] = str_replace(' ', '', $data['contactPhone']);
        }
        if (($data['method'] ?? null) === self::PICKUP) {
            $data['address'] = $data['district'] = $data['reference'] = null;
        }

        return $data;
    }

    private static function text(string $key): ?string
    {
        $value = trim((string) config('commerce.delivery.'.$key));

        return $value === '' ? null : $value;
    }
}

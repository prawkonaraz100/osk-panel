<?php

namespace App\Modules\IdentityTenant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class PasswordRecoveryController
{
    public function __construct(
        private readonly PasswordRecoveryService $passwordRecovery,
    ) {}

    public function forgot(Request $request): Response
    {
        $input = $this->validated($request, [
            'identifier' => ['required', 'string', 'max:320'],
        ]);

        $this->passwordRecovery->forgot(
            (string) $input['identifier'],
            $request->ip(),
        );

        return response('', 202);
    }

    public function reset(Request $request): Response
    {
        $input = $this->validated($request, [
            'token' => ['required', 'string', 'max:512'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $this->passwordRecovery->reset(
            (string) $input['token'],
            (string) $input['password'],
            $request->ip(),
        );

        return response('', 200);
    }

    /**
     * @param  array<string,array<int,string>>  $rules
     * @return array<string,mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $input = $request->all();
        $unknown = array_values(array_diff(array_keys($input), array_keys($rules)));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'request' => ['Unknown fields: '.implode(', ', $unknown)],
            ]);
        }

        return Validator::make($input, $rules)->validate();
    }
}

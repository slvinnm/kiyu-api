<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Receptionist = 'receptionist';
    case Nurse = 'nurse';
    case Doctor = 'doctor';
    case Pharmacist = 'pharmacist';
    case Pharmacy = 'pharmacy';
    case Lab = 'lab';
    case Billing = 'billing';
    case Patient = 'patient';
}

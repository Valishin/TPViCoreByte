<?php

namespace App\Enums;

/** Los 14 alérgenos de declaración obligatoria (Reglamento UE 1169/2011). */
enum Allergen: string
{
    case Gluten = 'gluten';
    case Crustaceans = 'crustaceos';
    case Eggs = 'huevos';
    case Fish = 'pescado';
    case Peanuts = 'cacahuetes';
    case Soy = 'soja';
    case Milk = 'lacteos';
    case Nuts = 'frutos_cascara';
    case Celery = 'apio';
    case Mustard = 'mostaza';
    case Sesame = 'sesamo';
    case Sulphites = 'sulfitos';
    case Lupin = 'altramuces';
    case Molluscs = 'moluscos';

    public function label(): string
    {
        return match ($this) {
            self::Gluten => 'Cereales con gluten',
            self::Crustaceans => 'Crustáceos',
            self::Eggs => 'Huevos',
            self::Fish => 'Pescado',
            self::Peanuts => 'Cacahuetes',
            self::Soy => 'Soja',
            self::Milk => 'Leche y lácteos',
            self::Nuts => 'Frutos de cáscara',
            self::Celery => 'Apio',
            self::Mustard => 'Mostaza',
            self::Sesame => 'Granos de sésamo',
            self::Sulphites => 'Dióxido de azufre y sulfitos',
            self::Lupin => 'Altramuces',
            self::Molluscs => 'Moluscos',
        };
    }
}

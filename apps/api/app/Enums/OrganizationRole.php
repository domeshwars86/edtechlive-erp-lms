<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Admin = 'admin';
    case Management = 'management';
    case Teacher = 'teacher';
    case Student = 'student';
    case Parent = 'parent';
    case IndependentEducator = 'independent_educator';
}

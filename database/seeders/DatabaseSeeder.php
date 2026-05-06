<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'General Affairs', 'code' => 'GA', 'description' => 'General Affairs Department'],
            ['name' => 'Logistics', 'code' => 'LOG', 'description' => 'Logistics Department'],
            ['name' => 'Accounting', 'code' => 'ACC', 'description' => 'Accounting & Finance Department'],
            ['name' => 'Purchasing', 'code' => 'PUR', 'description' => 'Purchasing Department'],
            ['name' => 'Engineering', 'code' => 'ENG', 'description' => 'Engineering Department'],
            ['name' => 'IT', 'code' => 'IT', 'description' => 'Information Technology Department'],
            ['name' => 'Workshop', 'code' => 'WS', 'description' => 'Workshop Department'],
            ['name' => 'Management', 'code' => 'MGT', 'description' => 'Management Department'],
        ];

        foreach ($departments as $dept) {
            Department::create($dept);
        }

        $roles = [
            ['name' => 'User / Requestor', 'code' => 'user', 'description' => 'Creates requests; inputs initial data for project transactions'],
            ['name' => 'Department Head', 'code' => 'dept_head', 'description' => 'First-level validation of all Material and Service Requests'],
            ['name' => 'General Affairs', 'code' => 'ga', 'description' => 'Handles procurement for asset and internal category items; Pihak II for Internal/Asset'],
            ['name' => 'Logistics', 'code' => 'log', 'description' => 'Handles procurement and SR validation for customer items; Pihak II for Customer'],
            ['name' => 'Accounting', 'code' => 'accounting', 'description' => 'Validates and activates master item records; inputs COA, asset code, category'],
            ['name' => 'Purchasing', 'code' => 'purchasing', 'description' => 'Creates Purchase Orders; inputs vendor, price, term of payment; maintains vendor master; Pihak III'],
            ['name' => 'Pihak I', 'code' => 'pihak_1', 'description' => 'Inputs initial price on PR; creates Pre RD from approved PO'],
            ['name' => 'Pihak II', 'code' => 'pihak_2', 'description' => 'Multi-tier approval authority for PR and PO; count of tiers scales with transaction value'],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }

        $adminDept = Department::where('code', 'MGT')->first();

        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@erp.com',
            'password' => Hash::make('password'),
            'department_id' => $adminDept->id,
            'employee_id' => 'EMP-001',
            'phone' => '08123456789',
            'is_active' => true,
        ]);

        $admin->roles()->attach(Role::all());

        $users = [
            ['name' => 'GA Staff', 'email' => 'ga@erp.com', 'department_code' => 'GA', 'role_codes' => ['user', 'ga', 'pihak_1', 'pihak_2']],
            ['name' => 'Logistics Staff', 'email' => 'log@erp.com', 'department_code' => 'LOG', 'role_codes' => ['user', 'log', 'pihak_1', 'pihak_2']],
            ['name' => 'Accounting Staff', 'email' => 'accounting@erp.com', 'department_code' => 'ACC', 'role_codes' => ['accounting']],
            ['name' => 'Purchasing Staff', 'email' => 'purchasing@erp.com', 'department_code' => 'PUR', 'role_codes' => ['purchasing']],
            ['name' => 'Dept Head GA', 'email' => 'head.ga@erp.com', 'department_code' => 'GA', 'role_codes' => ['dept_head', 'pihak_2']],
            ['name' => 'Dept Head Log', 'email' => 'head.log@erp.com', 'department_code' => 'LOG', 'role_codes' => ['dept_head', 'pihak_2']],
            ['name' => 'Dept Head Eng', 'email' => 'head.eng@erp.com', 'department_code' => 'ENG', 'role_codes' => ['dept_head']],
            ['name' => 'Regular User', 'email' => 'user@erp.com', 'department_code' => 'ENG', 'role_codes' => ['user', 'pihak_1']],
        ];

        foreach ($users as $index => $userData) {
            $department = Department::where('code', $userData['department_code'])->first();
            $roles = Role::whereIn('code', $userData['role_codes'])->get();

            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make('password'),
                'department_id' => $department->id,
                'employee_id' => 'EMP-' . str_pad($index + 2, 3, '0', STR_PAD_LEFT),
                'is_active' => true,
            ]);

            $user->roles()->attach($roles);
        }

        $this->call(ApprovalTierSeeder::class);
    }
}
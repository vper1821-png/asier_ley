<?php
// SecureLab2v - Database seeding

function seedAdminUser() {
    static $seeded = false;
    if ($seeded) return;
    $seeded = true;

    $db = Database::getInstance();
    $admin = $db->findOne('users', ['email' => ADMIN_EMAIL]);

    if (!$admin) {
        $db->insertOne('users', [
            'email' => ADMIN_EMAIL,
            'password' => Auth::hashPassword(ADMIN_PASSWORD),
            'companyName' => 'SecureLab Admin',
            'domain' => '',
            'isActive' => true,
            'isAdmin' => true,
            'role' => 'superadmin',
            'planType' => 'enterprise',
            'paymentStatus' => 'active',
            'onboardingComplete' => true,
            'twoFactorEnabled' => false,
        ]);
        error_log('[SEED] Admin user created: ' . ADMIN_EMAIL);
    }
}

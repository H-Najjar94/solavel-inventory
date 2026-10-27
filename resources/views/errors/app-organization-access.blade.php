@php
    $copy = [
        'en' => ['Access unavailable', 'Your account cannot access :app in the selected organization.', 'Choose an organization below to open this app, or return to the portal.', 'Back to portal', 'Switch organization'],
        'ar' => ['الوصول غير متاح', 'لا يملك حسابك صلاحية الوصول إلى :app في المؤسسة المحددة.', 'اختر مؤسسة أدناه لفتح التطبيق، أو عُد إلى البوابة.', 'العودة إلى البوابة', 'تبديل المؤسسة'],
        'fr' => ['Accès indisponible', 'Votre compte ne peut pas accéder à :app dans l’organisation sélectionnée.', 'Choisissez une organisation ci-dessous ou revenez au portail.', 'Retour au portail', 'Changer d’organisation'],
        'de' => ['Kein Zugriff', 'Ihr Konto hat keinen Zugriff auf :app in der ausgewählten Organisation.', 'Wählen Sie unten eine Organisation oder kehren Sie zum Portal zurück.', 'Zurück zum Portal', 'Organisation wechseln'],
        'es' => ['Acceso no disponible', 'Su cuenta no tiene acceso a :app en la organización seleccionada.', 'Elija una organización a continuación o vuelva al portal.', 'Volver al portal', 'Cambiar de organización'],
        'it' => ['Accesso non disponibile', 'Il tuo account non può accedere a :app nell’organizzazione selezionata.', 'Scegli un’organizzazione qui sotto oppure torna al portale.', 'Torna al portale', 'Cambia organizzazione'],
        'pt' => ['Acesso indisponível', 'A sua conta não tem acesso a :app na organização selecionada.', 'Escolha uma organização abaixo ou volte ao portal.', 'Voltar ao portal', 'Mudar de organização'],
        'tr' => ['Erişim yok', 'Hesabınız seçili kuruluşta :app uygulamasına erişemiyor.', 'Aşağıdan bir kuruluş seçin veya portala dönün.', 'Portala dön', 'Kuruluş değiştir'],
        'ru' => ['Доступ недоступен', 'У вашей учётной записи нет доступа к :app в выбранной организации.', 'Выберите организацию ниже или вернитесь на портал.', 'Вернуться на портал', 'Сменить организацию'],
        'ur' => ['رسائی دستیاب نہیں', 'آپ کے اکاؤنٹ کو منتخب تنظیم میں :app تک رسائی حاصل نہیں ہے۔', 'نیچے تنظیم منتخب کریں یا پورٹل پر واپس جائیں۔', 'پورٹل پر واپس جائیں', 'تنظیم تبدیل کریں'],
        'zh' => ['无法访问', '您的账号无权在所选组织中访问 :app。', '请选择下方有权限的组织，或返回门户。', '返回门户', '切换组织'],
    ];
    $c = $copy[app()->getLocale()] ?? $copy['en'];
    $appKey = $appKey ?? 'hr';
    $appName = $appName ?? (['hr'=>'SolaHR','finance'=>'SolaCount','inventory'=>'SolaStock','projects'=>'SolaProjects'][$appKey] ?? 'Solavel');
    if (! isset($organizations)) {
        $identity = (int) (auth()->user()?->central_user_id ?? 0);
        $organizations = app(\App\Services\Access\AppOrganizationChoices::class)->forUser($identity, $appKey);
    }
    $central = rtrim((string) (config('sso.central_app_url') ?: 'https://solavel.com'), '/');
    $paths = ['hr'=>'/sso/hr/redirect','finance'=>'/sso/finance/redirect','projects'=>'/sso/projects/redirect','inventory'=>'/sso/inventory'];
    $actions = [];
    foreach ($organizations as $choice) {
        $actions[] = ['style'=>'organization','icon'=>'swap','label'=>$choice['name'],'subtitle'=>$c[4], 'logo_url'=>$choice['logo_url'] ?? null,
            'url'=>$central.$paths[$appKey].'?'.http_build_query(['organization_id'=>$choice['central_org_id'],'lang'=>app()->getLocale()])];
    }
    $actions[] = ['style'=>'ghost','icon'=>'portal','label'=>$c[3],'url'=>$central.'/portal'];
    $branded = ['status'=>403, 'icon'=>'lock', 'chip'=>$appName, 'title'=>$c[0],
        'line'=>str_replace(':app', $appName, $c[1]), 'app_name'=>'Solavel', 'app_subtitle'=>$appName,
        'info'=>count($organizations) ? ['text'=>$c[2], 'rows'=>[]] : null,
        'actions'=>$actions, 'links'=>[], 'report'=>false];
@endphp
@include('errors._branded', ['b'=>$branded])


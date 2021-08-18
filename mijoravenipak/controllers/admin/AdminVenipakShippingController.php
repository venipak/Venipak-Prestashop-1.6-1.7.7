<?php

class AdminVenipakShippingController extends ModuleAdminController
{
    /** @var bool Is bootstrap used */
    public $bootstrap = true;

    private $total_orders = 0;

    /**
     * AdminOmnivaltShippingStoresController class constructor
     *
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function __construct()
    {
        $this->list_no_link = true;
        $this->className = 'Order';
        $this->table = 'order';
        parent::__construct();
        $this->toolbar_title = $this->l('Venipak Manifest - Ready Orders');
        $this->_select = '
            mo.labels_numbers as label_number,
            CONCAT(LEFT(c.`firstname`, 1), \'. \', c.`lastname`) AS `customer`,
            osl.`name` AS `osname`,
            os.`color`,
            a.id_order AS id_print,
            a.id_order AS id_label_print
		';
        $this->_join = '
            LEFT JOIN `' . _DB_PREFIX_ . 'mjvp_orders` mo ON (mo.`id_order` = a.`id_order`)
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON (c.`id_customer` = a.`id_customer`)
            LEFT JOIN `' . _DB_PREFIX_ . 'carrier` carrier ON (carrier.`id_carrier` = a.`id_carrier`)
            LEFT JOIN `' . _DB_PREFIX_ . 'order_state` os ON (os.`id_order_state` = a.`current_state`)
            LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON (os.`id_order_state` = osl.`id_order_state` AND osl.`id_lang` = ' . (int) $this->context->language->id . ')
    ';

        $this->_sql = '
      SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` a
      WHERE 1 ' . Shop::addSqlRestrictionOnLang('a');
        $this->total_orders = DB::getInstance()->getValue($this->_sql);

        $this->_where = ' AND carrier.id_reference IN ('
            . Configuration::get('MJVP_COURIER_ID_REFERENCE') . ','
            . Configuration::get('MJVP_PICKUP_ID_REFERENCE') . ")";
        $statuses = OrderState::getOrderStates((int) $this->context->language->id);
        foreach ($statuses as $status) {
            $this->statuses_array[$status['id_order_state']] = $status['name'];
        }

        if (Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_SHOP) {
            $this->errors[] = $this->l('Select shop');
        } else {
            $this->readyOrdersList();
        }
    }

    protected function readyOrdersList()
    {
        $this->fields_list = array(
            'id_order' => array(
                'title' => $this->l('ID'),
                'align' => 'text-center',
                'class' => 'fixed-width-xs',
                'search' => false,
            ),
            'id_shop' => array(
                'type' => 'text',
                'title' => $this->l('Shop'),
                'align' => 'center',
                'search' => false,
                'havingFilter' => false,
                'orderby' => false,
                'callback' => 'getShopNameById',
            ),
            'osname' => array(
                'title' => $this->l('Status'),
                'type' => 'select',
                'color' => 'color',
                'list' => $this->statuses_array,
                'filter_key' => 'os!id_order_state',
                'filter_type' => 'int',
                'order_key' => 'osname',
            ),
            'customer' => array(
                'title' => $this->l('Customer'),
                'havingFilter' => true,
            ),
            'label_number' => array(
                'type' => 'text',
                'title' => $this->l('Tracking number'),
                'havingFilter' => false,
            )
        );

        $this->fields_list['id_label_print'] = array(
            'title' => $this->l('PDF'),
            'align' => 'text-center',
            'search' => false,
            'orderby' => false,
            'callback' => 'labelBtn',
        );

        $this->actions = array('none');

        $this->bulk_actions = array(
            'generateVenipak' => array(
                'text' => $this->l('Generate Labels'),
                'icon' => 'icon-save'
            ),
        );
    }

    public function renderList()
    {
        switch (Shop::getContext()) {
            case Shop::CONTEXT_GROUP:
                $this->_where .= ' AND a.`id_shop` IN(' . implode(',', Shop::getContextListShopID()) . ')';
                break;

            case Shop::CONTEXT_SHOP:
                $this->_where .= Shop::addSqlRestrictionOnLang('a');
                break;

            default:
                break;
        }
        $this->_use_found_rows = false;

        unset($this->toolbar_btn['new']);

        return parent::renderList();
    }

    public function getShopNameById($id)
    {
        $shop = new Shop($id);
        return $shop->name;
    }

    public function labelBtn($id)
    {

        MijoraVenipak::checkForClass('MjvpDb');
        $cDb = new MjvpDb();
        $tracking_number = $cDb->getOrderValue('labels_numbers', ['id_order' => $id]);
        if (!$tracking_number) {
            return '<span class="btn-group-action">
                        <span class="btn-group">
                          <a class="btn btn-default" href="' . self::$currentIndex . '&token=' . $this->token . '&submitBulkgenerateVenipakLabelorder' . '&orderBox[]=' . $id . '"><i class="icon-save"></i>&nbsp;' . $this->l('Generate Label') . '
                          </a>
                        </span>
                    </span>';
        }
        return '<span class="btn-group-action">
                    <span class="btn-group">
                        <a class="btn btn-default" target="_blank" href="' . self::$currentIndex . '&token=' . $this->token . '&submitLabelorder' . '&id_order=' . $id . '"><i class="icon-tag"></i>&nbsp;' . $this->l('Label') . '
                        </a>
                    </span>
                </span>';
    }


    public function postProcess()
    {
        if(Tools::isSubmit('submitBulkgenerateVenipakLabelorder') || Tools::isSubmit('submitBulkgenerateVenipakorder'))
        {
            $orders = Tools::getValue('orderBox');
            $this->module->bulkActionSendLabels($orders);
        }
        if(Tools::isSubmit('submitLabelorder'))
        {
            MijoraVenipak::checkForClass('MjvpVenipak');
            $cVenipak = new MjvpVenipak();

            MijoraVenipak::checkForClass('MjvpDb');
            $cDb = new MjvpDb();

            MijoraVenipak::checkForClass('MjvpModuleConfig');
            $cModuleConfig = new MjvpModuleConfig();
            $username = Configuration::get($cModuleConfig->getConfigKey('username', 'API'));
            $password = Configuration::get($cModuleConfig->getConfigKey('password', 'API'));

            $id_order = Tools::getValue('id_order');
            $packageNumber = $cDb->getOrderValue('labels_numbers', array('id_order' => $id_order));
            $filename = md5($packageNumber . $password);
            $pdf = false;
            if(file_exists(_PS_MODULE_DIR_ . $this->module->name . '/pdf/' . $filename . '.pdf'))
            {
                $pdf = file_get_contents(_PS_MODULE_DIR_ . $this->module->name . '/pdf/' . $filename . '.pdf');
            }
            if(!$pdf)
                $pdf = $cVenipak->printLabel($username, $password, ['packages' => $packageNumber]);

            if ($pdf) { // check if its not empty
                $path = _PS_MODULE_DIR_ . $this->module->name . '/pdf/' . $filename . '.pdf';
                $is_saved = file_put_contents($path, $pdf);
                if (!$is_saved) { // make sure it was saved
                    throw new ItellaException("Failed to save label pdf to: " . $path);
                }

                // make sure there is nothing before headers
                if (ob_get_level()) ob_end_clean();
                header("Content-Type: application/pdf; name=\" " . $filename . ".pdf\"");
                header("Content-Transfer-Encoding: binary");
                // disable caching on client and proxies, if the download content vary
                header("Expires: 0");
                header("Cache-Control: no-cache, must-revalidate");
                header("Pragma: no-cache");
                readfile($path);
            } else {
                throw new ItellaException("Downloaded label data is empty.");
            }
        }
    }
}

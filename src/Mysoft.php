<?php

namespace Phpdev;

use GuzzleHttp\Client;

use GuzzleHttp\Exception\RequestException;

class MySoft
{
    private $apiUrl = 'https://edocumentapitest.mysoft.com.tr';
    private $tokenUrl = 'https://edocumentapitest.mysoft.com.tr/oauth/token';
    private $username;
    private $password;
    private $client;
    private $invocedata;
    
    public function __construct($username, $password)
    {
        $this->username = $username;
        $this->password = $password;

        $token = $this->generateToken();

        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token['access_token']
            ]
        ]);
    }

    private function generateToken()
    {
        $client = new Client([
            'base_uri' => $this->tokenUrl,
            'timeout' => 0,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        try {
            $response = $client->post('', [
                'form_params' => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'grant_type' => 'password',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return json_decode($e->getResponse()->getBody()->getContents(), true);
        }
    }


    private function prepareLines(){

        $line_arr = $this->invoicedata['fatura_detaylari'];
        if (count($line_arr) > 0){
            foreach ($line_arr as $key => $line) {
                // KDV DAHİL İSE 
                if ($line['kdv_dahil'] == 0){
                    $line_arr[$key]['satir_toplami'] = $line['urun_fiyati'] * $line['adet'];
                    $line_arr[$key]['kdv_dahil'] = ($line['urun_fiyati'] * $line['adet']) * ( 1 + ($line['kdv_orani'] / 100));
                    $line_arr[$key]['kdv_tutari'] = $line_arr[$key]['kdv_dahil'] - $line_arr[$key]['satir_toplami'];
                } else {
                    $urun_fiyati =  $line['urun_fiyati'] / ( 1 + ($line['kdv_orani'] / 100));
                    $line_arr[$key]['urun_fiyati'] = $line['urun_fiyati'] / ( 1 + ($line['kdv_orani'] / 100));
                    $line_arr[$key]['satir_toplami'] = $urun_fiyati * $line['adet'];
                    $line_arr[$key]['kdv_dahil'] = ($urun_fiyati * $line['adet']) * ( 1 + ($line['kdv_orani'] / 100));
                    $line_arr[$key]['kdv_tutari'] = $line_arr[$key]['kdv_dahil'] - $line_arr[$key]['satir_toplami'];
                }
                  
            }
        }
        $this->invoicedata['fatura_detaylari'] = $line_arr;
    }

    private function prepareTotal(){

        $line_arr = $this->invoicedata['fatura_detaylari'];

        $total_arr = [
            'toplam_mal_tutari' => 0,
            'toplam_kdv_dahil_tutar' =>0,
            'toplam_kdv' => 0
        ];
    
        foreach ($line_arr as $line){
                $total_arr['toplam_mal_tutari'] += $line['satir_toplami'];
                $total_arr['toplam_kdv_dahil_tutar'] += $line['kdv_dahil'];
                $total_arr['toplam_kdv'] += $line['kdv_tutari'];
        }
        
        $this->invoicedata['toplam'] = $total_arr;

    }

    private function prepareProducts(){
        
        $product_arr = array();

        foreach($this->invoicedata['fatura_detaylari'] as $product){
            if (strlen($product['urun_kodu'])>0){
                $pcode = $product['urun_kodu']; 
            } else {
                $pcode=sprintf("%u",crc32($product['urun_adi']));
            }
            
            array_push($product_arr,[
                "isNewProduct" => true,
                "product" => [
                    "productType" => "Stok",
                    "productName" => $product['urun_adi'],
                    "productCode" => $pcode,
                ],
                "unitCode" => "C62",
                "unitName" => "ADET",
                "qty" => $product['adet'],
                "unitPriceTra" => $product['urun_fiyati'],
                "discAmtTra" => $product['iskonto_tutari'],
                "amtTra" => $product['satir_toplami'],
                "vatRate" => $product['kdv_orani'],
                "amtVatTra" => $product['kdv_tutari'],
                #"discRate" => "60,00",
                #"discAmtTra" => "60,00",
                #"note" => "Stoğa yüzde 18 KDV uygulandı",
                #"oivRate" => "12",
                #"oivAmtTra" => "12",
                #"otvCode" => "A",
                #"otvRate" => "5",
                #"otvAmtTra" => "5",
                #"otvTaxExemptionReasonCode" => "45",
                "isKDVInclude" => 0,
            ]);

        }
        
        return $product_arr;

    }

    // Fatura bilgilerini Mysoft'a göndermek için hazırlıyoruz.
    private function prepareInvoce(){
        // Düzenlenmemiş Fatura Bilgileri
        // Satırları Düzenliyoruz.
        $this->prepareLines();
        // Toplamları Düzenliyoruz.
        $this->prepareTotal();
        // Toplam ve Satırlar Düzenlendikten Sonra MySoft Veri Paremetlerine Yerleştiriyoruz.
        
        // My soft için ayarlanmış product dizisini alıyoruz
        $products = $this->prepareProducts();

        $invoice_arr = $this->invoicedata;   
        
        $invoice_prepare = [
            "id" => "",
            "isCalculateByApi" => "false",
            "eDocumentType" => $invoice_arr['fatura_genel_bilgiler']['efatura_tipi'],
            "isThrowExceptionOnEDocumentTypeChange" => "true",
            #"connectorGuid" => "3ADEFE96-9B5E-498A-B744-3DF27D574731",
            "profile" => $invoice_arr['fatura_genel_bilgiler']['fatura_senaryo'],
            "invoiceType" => $invoice_arr['fatura_genel_bilgiler']['fatura_tipi'],
            #"tenantIdentifierNumber" => "",
            "prefix" => $invoice_arr['fatura_genel_bilgiler']['fatura_numarasi_onek'],
            "docDate" => $invoice_arr['fatura_genel_bilgiler']['fatura_tarihi'],
            "docTime" => $invoice_arr['fatura_genel_bilgiler']['fatura_saati'],
            #"dueDate" => "2023-03-28 12:00:00",
            "currencyCode" => $invoice_arr['fatura_genel_bilgiler']['doviz'],
            "currencyRate" => "1,000000",
            "senderType" =>$invoice_arr['fatura_genel_bilgiler']['fatura_gonderim_turu'],
            "orderNo" => $invoice_arr['fatura_genel_bilgiler']['siparis_numarasi'],
            "orderDate" => $invoice_arr['fatura_genel_bilgiler']['siparis_tarihi'],
            #"billingRefInvoiceNo" => "SDS95655989289",
            #"billingRefInvoiceDate" => "2023-04-01 12:00:00",
            #"billingRefNote" => "İstenilen stok gönderilmedi",
            #"categoryName" => "string",
            #"isReplacesEDespatch" => "1",
            "pkAlias" => $invoice_arr['alici']['fatura_posta_adresi'],
            "isNewAccount" => true,
            "isNewAcc" => true,
            // invoiceAccount anahtar değer çiftlerini ekleyin
            "invoiceAccount" => [
                #"accountCode" => "558586",
                "taxOffice" => [
                    "code" =>  $invoice_arr['alici']['vergi_dairesi_kod'],
                    "name" => $invoice_arr['alici']['vergi_dairesi'],
                ],
                "telephone1" => $invoice_arr['alici']['telefon'],
                "email1"    => $invoice_arr['alici']['eposta_adres'],
                "mobilePhone1" => $invoice_arr['alici']['gsm'],
                "postalCode" => $invoice_arr['alici']['postakodu'],
                "streetName" => $invoice_arr['alici']['sokak'],
                "blockName" => $invoice_arr['alici']['blok'],
                "buildingName" => $invoice_arr['alici']['bina'],
                "buildingNumber" => $invoice_arr['alici']['bina_no'],
                "region" => "YTÜ Davutpaşa Kampüsü Teknoloji Gelşt. Bölgesi",
                "district" => $invoice_arr['alici']['mahalle'],
                #"note" => "Açıklama",
                "accountName" => $invoice_arr['alici']['unvan'],
                "identifierNumber" => $invoice_arr['alici']['vergi_no'],
                "vknTckn" => $invoice_arr['alici']['vergi_no'],
                "city" => [
                    "code" => NULL,
                    "name" => $invoice_arr['alici']['il'],
                ],
                "country" => [
                    "code" => $invoice_arr['alici']['ulke_kod'],
                    "name" => $invoice_arr['alici']['ulke'],
                ],
                "citySubdivision" => $invoice_arr['alici']['ilce'],
            ],
            "isManuelCalculation" => true,
            "invoiceCalculation" => [
                "lineExtensionAmount" => $invoice_arr['toplam']['toplam_mal_tutari'],
                "taxExclusiveAmount" => $invoice_arr['toplam']['toplam_mal_tutari'],
                "taxInclusiveAmount" => $invoice_arr['toplam']['toplam_kdv_dahil_tutar'],
                "payableAmount" =>  $invoice_arr['toplam']['toplam_kdv_dahil_tutar']
            ],
            "invoiceDetail" => $products,
            /* "isInternetSales" => "0",
            "internetShipmentInfo" => [
                "webSiteUrl" => "https://mysoft.com.tr",
                "paymentType" => "KREDIKARTI/BANKAKARTI",
                "internetAccountName" => "mysoft",
                "paymentDate" => "2023-04-01 12:00:00",
                "paymentNote" => "Kredi kartı",
                "shippingDate" => "2023-04-01 12:00:00",
                "shippingAccountName" => "MYSOFT",
                "shippingAccountVknTckn" => "11111111111",
            ], */
            "notes" => [
                [
                    "note" => "test",
                ],
            ],
        ];
        
        $this->invoicedata = $invoice_prepare;


   
    }


    public function createInvoiceDraft($invoice)
    {       
        $this->invoicedata = $invoice;
    
        $this->prepareInvoce();

        #die(json_encode($this->invoicedata));
        
        
        try {
            $response = $this->client->post('/api/Invoice/invoiceDraftNew', [
                'json' => $this->invoicedata
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return json_decode($e->getResponse()->getBody()->getContents(), true);
        }   
    }


    public function taxOfficeList()
    {       
        try {
            $response = $this->client->get('api/GeneralCard/taxOffice');

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            return json_decode($e->getResponse()->getBody()->getContents(), true);
        }   
    }

    // Diğer fonksiyonlar (createInvoice, getInvoice, cancelInvoice)
}

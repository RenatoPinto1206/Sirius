<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Transaction;
use App\Contact;
use App\Product;
use App\Business;
use App\City;
use App\Unit;
use App\Variation;
use App\ProductVariation;
use App\VariationLocationDetails;
use App\PurchaseLine;
use App\BusinessLocation;

class PurchaseXmlController extends Controller
{
	public function index(){

		if (!auth()->user()->can('user.view') && !auth()->user()->can('user.create')) {
			abort(403, 'Unauthorized action.');
		}

		return view('purchase_xml.index');

	}

	public function verXml(Request $request){
		if ($request->hasFile('file')){

			$arquivo = $request->hasFile('file');
			$xml = simplexml_load_file($request->file);

			$msgImport = $this->validaChave($xml->NFe->infNFe->attributes()->Id);

			if($msgImport == ""){
				$user_id = $request->session()->get('user.id');
				$business_id = request()->session()->get('user.business_id');

				$cidade = City::getCidadeCod($xml->NFe->infNFe->emit->enderEmit->cMun);
				$contact = [
					'business_id' => $business_id,
					'city_id' => $cidade->id,
					'cpf_cnpj' => $xml->NFe->infNFe->emit->CNPJ ? 
					$this->formataCnpj($xml->NFe->infNFe->emit->CNPJ) : 
					$this->formataCpf($xml->NFe->infNFe->emit->CPF),
					'ie_rg' => $xml->NFe->infNFe->emit->IE,
					'consumidor_final' => 1,
					'contribuinte' => 1,
					'rua' => $xml->NFe->infNFe->emit->enderEmit->xLgr,
					'numero' => $xml->NFe->infNFe->emit->enderEmit->nro,
					'bairro' => $xml->NFe->infNFe->emit->enderEmit->xBairro,
					'cep' => $xml->NFe->infNFe->emit->enderEmit->CEP,
					'type' => 'supplier',
					'name' => $xml->NFe->infNFe->emit->xNome,
					'mobile' => '',
					'created_by' => $user_id
				];

				$cnpj = $contact['cpf_cnpj'];
				$fornecedorNovo = Contact::where('cpf_cnpj', $cnpj)
				->where('type', 'supplier')
				->first();

				// $resFornecedor = $this->validaFornecedorCadastrado($contact);

				$itens = [];
				$contSemRegistro = 0;
				foreach($xml->NFe->infNFe->det as $item) {

					$produto = $this->validaProdutoCadastrado($item->prod->cEAN,
						$item->prod->xProd);

					$produtoNovo = $produto == null ? true : false;

					if($produtoNovo) $contSemRegistro++;

					$item = [
						'codigo' => $item->prod->cProd,
						'xProd' => $item->prod->xProd,
						'NCM' => $item->prod->NCM,
						'CFOP' => $item->prod->CFOP,
						'uCom' => $item->prod->uCom,
						'vUnCom' => $item->prod->vUnCom,
						'qCom' => $item->prod->qCom,
						'codBarras' => $item->prod->cEAN,
						'produtoNovo' => $produtoNovo,
						'produtoId' => $produtoNovo ? '0' : $produto->id,
					];
					array_push($itens, $item);
				}

				$chave = substr($xml->NFe->infNFe->attributes()->Id, 3, 44);

				$vFrete = number_format((double) $xml->NFe->infNFe->total->ICMSTot->vFrete, 
					2, ",", ".");

				$vDesc = $xml->NFe->infNFe->total->ICMSTot->vDesc;

				$dadosNf = [
					'chave' => $chave,
					'vProd' => $xml->NFe->infNFe->total->ICMSTot->vProd,
					'indPag' => $xml->NFe->infNFe->ide->indPag,
					'nNf' => $xml->NFe->infNFe->ide->nNF,
					'vFrete' => $vFrete,
					'vDesc' => $vDesc,
					'novoFornecedor' => $fornecedorNovo == null ? true : false
				];

				$fatura = [];
				if (!empty($xml->NFe->infNFe->cobr->dup))
				{
					foreach($xml->NFe->infNFe->cobr->dup as $dup) {
						$titulo = $dup->nDup;
						$vencimento = $dup->dVenc;
						$vencimento = explode('-', $vencimento);
						$vencimento = $vencimento[2]."/".$vencimento[1]."/".$vencimento[0];
						$vlr_parcela = number_format((double) $dup->vDup, 2, ",", ".");	

						$parcela = [
							'numero' => $titulo,
							'vencimento' => $vencimento,
							'valor_parcela' => $vlr_parcela
						];
						array_push($fatura, $parcela);
					}
				}

				$business_id = request()->session()->get('user.business_id');

				$business = Business::find($business_id);
				$cnpj = $business->cnpj;

				$cnpj = str_replace(".", "", $cnpj);
				$cnpj = str_replace("/", "", $cnpj);
				$cnpj = str_replace("-", "", $cnpj);
				$cnpj = str_replace(" ", "", $cnpj);

				$file = $request->file;
				$file_name = $chave . ".xml" ;


				if(!is_dir(public_path('xml_entrada/'.$cnpj))){
					mkdir(public_path('xml_entrada/'.$cnpj), 0777, true);
				}

				$pathXml = $file->move(public_path('xml_entrada/'.$cnpj), $file_name);
				$business_locations = BusinessLocation::forDropdown($business_id);

				return view('purchase.view_xml')
				->with('contact' , $contact)
				->with('itens' , $itens)
				->with('cidade' , $cidade)
				->with('fatura' , $fatura)
				->with('business_locations' , $business_locations)
				->with('dadosNf' , $dadosNf);

			}else{

			}

		}else{

		}
	}

	private function validaChave($chave){
		$msg = "";
		$chave = substr($chave, 3, 44);

		$cp = Transaction::
		where('chave', $chave)
		->first();

		// $manifesto = ManifestaDfe::
		// where('chave', $chave)
		// ->first();

		if($cp != null) $msg = "XML já importado";
		// if($manifesto != null) $msg .= "XML já importado através do manifesto fiscal";
		return $msg;
	}

	private function validaFornecedorCadastrado($data){
		$cnpj = $data['cpf_cnpj'];
		$fornecedor = Contact::where('cpf_cnpj', $cnpj)
		->where('type', 'supplier')
		->first();

		if($fornecedor == null){
			$contact = Contact::create($data);

			$fornecedor = Contact::find($contact->id);
		}

		return $fornecedor;

	}

	private function validaProdutoCadastrado($nome, $ean){
		$result = Product::
		where('sku', $ean)
		->where('sku', '!=', 'SEM GTIN')
		->first();

		if($result == null){
			$result = Product::
			where('name', $nome)
			->first();
		}

		//verifica por codBarras e nome o PROD

		return $result;
	}

	private function validaUnidadeCadastrada($nome, $user_id){
		$business_id = request()->session()->get('user.business_id');
		$unidade = Unit::where('short_name', $nome)
		->first();

		if($unidade != null){
			return $unidade;
		}

		//vai inserir
		$data = [
			'business_id' => $business_id,
			'actual_name' => $nome,
			'short_name' => $nome,
			'allow_decimal' => 0,
			'created_by' => $user_id
		];

		$u = Unit::create($data);
		$unidade = Unit::find($u->id);

		return $unidade;

	}

	private function formataCnpj($cnpj){
		$temp = substr($cnpj, 0, 2);
		$temp .= ".".substr($cnpj, 2, 3);
		$temp .= ".".substr($cnpj, 5, 3);
		$temp .= "/".substr($cnpj, 8, 4);
		$temp .= "-".substr($cnpj, 12, 2);
		return $temp;
	}

	private function formataCpf($cpf){
		$temp = substr($cpf, 0, 3);
		$temp .= ".".substr($cpf, 3, 3);
		$temp .= ".".substr($cpf, 6, 3);
		$temp .= "-".substr($cpf, 9, 2);

		return $temp;
	}

	public function save(Request $request){


		$business_id = request()->session()->get('user.business_id');
		$business = Business::find($business_id);

		$contact = json_decode($request->contact, true);
		$itens = json_decode($request->itens, true);
		$fatura = json_decode($request->fatura, true);
		$dadosNf = json_decode($request->dadosNf, true);

		$data = [
			'business_id' => $contact['business_id'],
			'city_id' => $contact['city_id'],
			'cpf_cnpj' => $contact['cpf_cnpj'],
			'ie_rg' => $contact['ie_rg'][0],
			'consumidor_final' => 1,
			'contribuinte' => 1,
			'rua' => $contact['rua'][0],
			'numero' => $contact['numero'][0],
			'bairro' => $contact['bairro'][0],
			'cep' => $contact['cep'][0],
			'type' => 'supplier',
			'name' => $contact['name'][0],
			'mobile' => '',
			'created_by' => $contact['created_by']
		];

		$user_id = $request->session()->get('user.id');

		$contact = $this->validaFornecedorCadastrado($data);

		$dataCompra = [
			'business_id' => $business_id,
			'type' => 'purchase',
			'status' => 'final',
			'payment_status' => 'paid',
			'contact_id' => $contact->id,
			'transaction_date' => date('Y-m-d H:i:s'),
			'created_by' => $user_id,
			'numero_nfe_entrada' => $dadosNf['nNf'][0],
			'chave' => $dadosNf['chave'],
			'estado' => 'APROVADO',
			'final_total' => $dadosNf['vProd'][0],
			'discount_amount' => $dadosNf['vDesc'][0],
			'discount_type' => $dadosNf['vDesc'][0] > 0 ? 'fixed' : NULL
		];

		$purchase = Transaction::create($dataCompra);

		foreach($itens as $i){

			$unidade = $this->validaUnidadeCadastrada($i['uCom'][0], $user_id);

			$cfop = $i['CFOP'][0];
			$lastCfop = substr($cfop, 1, 3);
			$produtoData = [
				'name' => $i['xProd'][0],
				'business_id' => $business_id,
				'unit_id' => $unidade->id,
				'tax_type' => 'inclusive',
				'barcode_type' => 'EAN-13',
				'sku' => $i['codBarras'][0] != 'SEM GTIN' ? $i['codBarras'][0] : $this->lastCodeProduct(),
				'created_by' => $user_id,
				'perc_icms' => 0,
				'perc_pis' => 0,
				'perc_cofins' => 0,
				'perc_ipi' => 0,
				'ncm' => $i['NCM'][0],
				'cfop_interno' => '5'.$lastCfop,
				'cfop_externo' => '6'.$lastCfop,
				'type' => 'single',
				'enable_stock' => 1,

				'cst_csosn' => $business->cst_csosn_padrao,
				'cst_pis' => $business->cst_cofins_padrao,
				'cst_cofins' => $business->cst_pis_padrao,
				'cst_ipi' => $business->cst_ipi_padrao,

			];

			// print_r($prod);
			$prodNovo = $this->validaProdutoCadastrado($i['xProd'][0], $i['codBarras'][0]);
			$prod = null;
			if($prodNovo == null){
				$prod = Product::create($produtoData);
			}else{
				$prod = $prodNovo;
			}


			//criar variação de produto

			//verfica variacao

			$dataProductVariation = [
				'product_id' => $prod->id,
				'name' => 'DUMMY'
			];

			$variacao = ProductVariation::where('product_id', $prod->id)->where('name', 'DUMMY')->first();
			$produtoVariacao = null;
			if($variacao == null){
				$produtoVariacao = ProductVariation::create($dataProductVariation);
			}else{
				$produtoVariacao = $variacao;
			}

			// criar variação

			$dataVariation = [
				'name' => 'DUMMY',
				'product_id' => $prod->id,
				'default_purchase_price' => $i['vUnCom'][0],
				'dpp_inc_tax' => $i['vUnCom'][0],
				'product_variation_id' => $produtoVariacao->id
			];

			$var = Variation::where('product_id', $prod->id)->where('name', 'DUMMY')
			->where('product_variation_id', $produtoVariacao->id)->first();
			$variacao = null;
			if($var == null){
				$variacao = Variation::create($dataVariation);
			}else{
				$variacao = $var;
			}

			//criar item compra
			$dataItemPurchase = [
				'transaction_id' => $purchase->id,
				'product_id'=> $prod->id,
				'variation_id' => $variacao->id,
				'quantity' => $i['qCom'][0],
				'purchase_price' => $i['vUnCom'][0]
			];

			$item = PurchaseLine::create($dataItemPurchase);

			//verificaStock

			if($prodNovo == null){
				//criar stock
				$this->openStock($business_id, $prod, $i['vUnCom'][0], $i['qCom'][0], $user_id, $request->location_id, 
					$variacao->id, $produtoVariacao->id);
				//add estoque

			}else{

				$current_stock = VariationLocationDetails::
				where('product_id', $prod->id)
				->where('location_id', $request->location_id)
				->value('qty_available');

				if($current_stock == null){
					$this->openStock($business_id, $prod, $i['vUnCom'][0], $i['qCom'][0], $user_id, 
						$request->location_id, $variacao->id, $produtoVariacao->id);

				}else{
					$current_stock->qty_available += $i['qCom'][0];
				}
			}

		}

	}

	private function lastCodeProduct(){
		$prod = Product::orderBy('id', 'desc')->first();
		$v = (int) $prod->sku;
		if($v<10) return '000' . ($v+1);
		elseif($v<100) return '00' . ($v+1);
		elseif($v<1000) return '0'.($v+1);
		else return $v+1;
	}


	private function openStock($business_id, $produto, $valorUnit, $quantidade, $user_id, $location_id, $variacao_id, 
		$product_variation_id){
		$transaction = Transaction::create(
			[
				'type' => 'opening_stock',
				'opening_stock_product_id' => $produto->id,
				'status' => 'received',
				'business_id' => $business_id,
				'transaction_date' => date('Y-m-d H:i:s'),
				'total_before_tax' => $valorUnit * $quantidade,
				'location_id' => $location_id,
				'final_total' => $valorUnit * $quantidade,
				'payment_status' => 'paid',
				'created_by' => $user_id
			]
		);

		VariationLocationDetails::create([
			'product_id' => $produto->id,
			'location_id' => $location_id,
			'variation_id' => $variacao_id,
			'product_variation_id' => $product_variation_id,
			'qty_available' => $quantidade
		]);

	}


}
<?php

//decode by http://www.yunlu99.com/
namespace app\index\controller;

use app\index\model\Accounts;
use app\index\model\Jobs;
use app\index\model\Kms;
use app\index\model\Pays;
use app\index\model\TaskLogs;
use app\index\model\Tasks;
use app\index\model\Users;
use app\index\validate\Sport;
use netease\Qrcode;
use sport\Step;
use think\exception\ValidateException;
use think\facade\Request;
use think\facade\Session;
class Ajax extends Common
{
	protected $middleware = ["app\\middleware\\CheckLoginUser", "app\\middleware\\CheckAjaxRequest"];
	public function __construct()
	{
	}
	public function bilibili($act = null)
	{
		// Compatibility entry for deployments that still expose controller auto-routing.
		return (new \app\index\controller\Bilibili())->handle($act);
	}
	public function sport($act = null)
	{
		switch ($act) {
			case "add":
				$_var_29 = new Step();
				$_var_30 = Request::post();
				if (Tasks::checkTaskPower("step") && empty(Session::get("user.vip_start"))) {
					return resultJson(-1, "您需要开通VIP会员才可以使用该功能");
				} else {
					$_var_31 = $_var_29->login($_var_30["username"], $_var_30["password"]);
					$_var_32 = @json_decode($_var_31["body"], true);
					if (@$_var_32["result"] == "ok") {
						$_var_30 = ["user_id" => $_var_32["token_info"]["user_id"], "username" => $_var_30["username"], "password" => $_var_30["password"], "nickname" => $_var_32["thirdparty_info"]["nickname"], "login_token" => $_var_32["token_info"]["login_token"], "app_token" => $_var_32["token_info"]["app_token"]];
						if (Accounts::where("user_id", "=", $_var_30["user_id"])->where("uid", "<>", Session::get("user.uid"))->find()) {
							return resultJson(-1, "系统已存在该账号，无法继续添加");
						} else {
							return Accounts::add("sport", $_var_30["user_id"], $_var_30);
						}
					} else {
						return resultJson(0, "登录失败，请检查账号密码是否正确");
					}
				}
				break;
			case "step":
				$_var_30 = Request::post();
				try {
					validate(Sport::class)->check($_var_30);
				} catch (ValidateException $_var_33) {
					return resultJson(-1, $_var_33->getMessage());
				}
				$_var_34 = Accounts::where("user_id", "=", $_var_30["user_id"])->where("type", "=", "sport")->find();
				$_var_35 = unserialize($_var_34["data"]);
				$_var_36 = ["username" => $_var_35["username"], "password" => $_var_35["password"], "step_start" => (float) $_var_30["step_start"], "step_stop" => (float) $_var_30["step_stop"]];
				$_var_36 = serialize($_var_36);
				if (Jobs::where("type", "=", "sport")->where("user_id", "=", $_var_30["user_id"])->where("uid", "=", Session::get("user.uid"))->update(["data" => $_var_36])) {
					return resultJson(1, "修改成功");
				} else {
					return resultJson(0, "修改失败");
				}
				break;
			case "delete":
				$_var_37 = Request::post("user_id");
				if (Accounts::delByUserId($_var_37) && Jobs::delJob("sport", $_var_37) && TaskLogs::deleteLogs("sport", $_var_37)) {
					return resultJson(1, "删除成功");
				} else {
					return resultJson(0, "删除失败");
				}
				break;
		}
	}
	public function heybox($act = null)
	{
		if ($act === "add") {
			$heyboxId = trim((string)Request::post("heybox_id", ""));
			$pkey = trim((string)Request::post("pkey", ""));
			if ($heyboxId === "" || $pkey === "" || strlen($heyboxId) > 32 || strlen($pkey) > 1024) {
				return resultJson(0, "heybox_id 或 pkey 格式错误");
			}
			$account = [
				"heybox_id" => $heyboxId,
				"pkey" => $pkey,
				"imei" => bin2hex(random_bytes(8)),
				"displayname" => "小黑盒用户 " . $heyboxId,
				"avatar" => "",
			];
			return Accounts::add("heybox", $heyboxId, $account);
		}
		return $this->accountAction("heybox", $act);
	}

	public function qrcode($act = null)
	{
		if ($act === "uploads") {
			$file = Request::file("file");
			$type = (string)Request::post("type", "");
			if (!$file || !in_array($type, ["alipay", "qq", "wechat"], true)) {
				return resultJson(0, "上传参数错误");
			}
			$path = $file->getPathname();
			if (!is_file($path) || filesize($path) > 2 * 1024 * 1024) {
				return resultJson(0, "图片不能超过 2MB");
			}
			$finfo = new \finfo(FILEINFO_MIME_TYPE);
			if (!in_array($finfo->file($path), ["image/png", "image/jpeg", "image/webp"], true)) {
				return resultJson(0, "仅支持 PNG、JPEG 或 WebP 图片");
			}
			try {
				$text = (new \Zxing\QrReader($path))->text();
			} catch (\Throwable $exception) {
				$text = false;
			}
			if (!is_string($text) || $text === "" || strlen($text) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) {
				return resultJson(0, "未识别到有效二维码内容");
			}
			return resultJson(1, "识别成功", ["type" => $type, "url" => $text]);
		}
		if ($act === "create") {
			$data = Request::post();
			$name = trim((string)($data["name"] ?? ""));
			if ($name === "" || mb_strlen($name) > 40) {
				return resultJson(0, "收款识别码长度应为 1 到 40 个字符");
			}
			foreach (["alipay_url", "qq_url", "wechat_url"] as $field) {
				$value = trim((string)($data[$field] ?? ""));
				if ($value === "" || strlen($value) > 2048 || preg_match('/[\x00-\x1F]/', $value)) {
					return resultJson(0, "收款码内容格式错误");
				}
				$data[$field] = $value;
			}
			$data = [
				"name" => $name,
				"alipay_url" => $data["alipay_url"],
				"qq_url" => $data["qq_url"],
				"wechat_url" => $data["wechat_url"],
			];
			$saved = Accounts::addQrcode("qrcode", $name, $data);
			$payload = get_Domain() . "index/index/qrcode?name=" . rawurlencode($name);
			ob_start();
			(new Qrcode())->png($payload, false, QR_ECLEVEL_M, 8, 2);
			$image = base64_encode((string)ob_get_clean());
			return resultJson(1, "生成成功", $image);
		}
		if ($act === "delete") {
			$userId = (string)Request::post("user_id", "");
			return Accounts::delQrcodeByUserId($userId)
				? resultJson(1, "删除成功")
				: resultJson(0, "删除失败");
		}
		return resultJson(0, "未知操作");
	}

	private function accountAction(string $type, $act)
	{
		$userId = trim((string)Request::post("user_id", ""));
		if ($userId === "") {
			return resultJson(0, "缺少账号标识");
		}
		$account = Accounts::where("type", "=", $type)
			->where("user_id", "=", $userId)
			->where("uid", "=", Session::get("user.uid"))
			->find();
		if (!$account) {
			return resultJson(0, "账号不存在或无权操作");
		}

		if ($act === "delete") {
			$deleted = Accounts::delByUserId($userId);
			Jobs::where("type", "=", $type)->where("user_id", "=", $userId)->where("uid", "=", Session::get("user.uid"))->delete();
			TaskLogs::deleteLogs($type, $userId);
			return $deleted ? resultJson(1, "删除成功") : resultJson(0, "删除失败");
		}
		if ($act === "logs") {
			return TaskLogs::searchLogs($type, $userId);
		}
		if ($act === "reExecute") {
			$query = Jobs::where("type", "=", $type)
				->where("user_id", "=", $userId)
				->where("uid", "=", Session::get("user.uid"))
				->where("state", "=", 1);
			if (count($query->select()) === 0) {
				return resultJson(1, "没有需要补挂的任务");
			}
			$query->update(["nextExecute" => time()]);
			return resultJson(1, "申请补挂成功，请稍后查看任务运行情况");
		}
		if ($act === "set") {
			$mode = (string)Request::post("act", "");
			if ($mode === "timing") {
				$timing = trim((string)Request::post("timing", ""));
				if ($timing !== "" && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timing)) {
					return resultJson(0, "时间格式应为 HH:MM");
				}
				Accounts::where("id", "=", $account["id"])->update(["timing" => $timing ?: null]);
				return resultJson(1, "保存成功");
			}
			$do = trim((string)Request::post("do", ""));
			$job = Jobs::where("type", "=", $type)
				->where("user_id", "=", $userId)
				->where("uid", "=", Session::get("user.uid"))
				->where("do", "=", $do)
				->find();
			if (!$job) {
				return resultJson(0, "任务不存在");
			}
			if ($mode === "zt") {
				if (Tasks::checkTaskPower($do) && empty(Session::get("user.vip_start"))) {
					return resultJson(-1, "您需要开通 VIP 才可以使用该功能");
				}
				$state = (int)$job["state"];
				$job->save(["state" => $state === -1 ? 1 : ($state ^ 1)]);
				return resultJson(1, "修改成功");
			}
			$config = Request::post("config", []);
			if (is_string($config)) {
				$config = json_decode($config, true);
			}
			if (!is_array($config)) {
				return resultJson(0, "任务配置格式错误");
			}
			$job->save(["data" => serialize($config)]);
			return resultJson(1, "保存成功");
		}
		return resultJson(0, "未知操作");
	}

	public function user($act = null)
	{
		switch ($act) {
			case "profile":
				$_var_48 = Request::Post();
				if (Users::updateByUid(Session::get("user.uid"), $_var_48)) {
					return resultJson(1, "修改成功");
				} else {
					return resultJson(0, "修改失败，无修改");
				}
				break;
			case "passWord":
				$_var_48 = Request::post();
				$_var_49 = Users::changePassWord(Session::get("user.uid"), $_var_48);
				return $_var_49;
				break;
		}
	}
	public function shop($act = null)
	{
		switch ($act) {
			case "buy":
				$_var_55 = Request::post();
				if ($_var_55["pay_type"] == "ypay" && $_var_55["shop"] == "vip") {
					return Pays::YpayVip($_var_55);
				} elseif ($_var_55["pay_type"] == "ypay" && $_var_55["shop"] == "quota") {
					return Pays::YpayQuota($_var_55);
				} else {
					return Pays::Submit_Pay($_var_55);
				}
				break;
			case "activate":
				$_var_55 = Request::post();
				return Kms::activate($_var_55);
				break;
		}
	}
	public function agent($act = null)
	{
		switch ($act) {
			case "kmList":
				return Kms::getMyList();
				break;
			case "delKm":
				$_var_56 = Request::post("id");
				if (Kms::where("id", "=", $_var_56)->where("uid", "=", Session::get("user.uid"))->delete()) {
					return resultJson(1, "删除成功");
				} else {
					return resultJson(0, "删除失败");
				}
				break;
			case "delUsedKm":
				if (Kms::where("useid", "<>", "0")->where("uid", "=", Session::get("user.uid"))->where("zid", "=", WEB_ID)->delete()) {
					return resultJson(1, "清空成功");
				} else {
					return resultJson(0, "没有可清空的卡密");
				}
				break;
			case "getPrice":
				$_var_57 = Request::post();
				switch ($_var_57["type"]) {
					case "vip":
						$_var_58 = $_var_57["num"] * config("sys.vip_price_" . $_var_57["value"] . "");
						$_var_59 = config("sys.agent_give_z_" . Session::get("user.agent") . "");
						$_var_60 = round($_var_58 * $_var_59 / 10, 2);
						$_var_60 = ["name" => "VIP卡密", "value" => is_Vip_Month($_var_57["value"]) . " 个月", "num" => $_var_57["num"] . " 张", "oprice" => $_var_58 . "元", "zk" => $_var_59 . "折", "price" => $_var_60 . "元"];
						return resultJson(1, "获取成功", $_var_60);
						break;
					case "quota":
						$_var_58 = $_var_57["num"] * config("sys.quota_price_" . $_var_57["value"] . "");
						$_var_59 = config("sys.agent_give_z_" . Session::get("user.agent") . "");
						$_var_60 = round($_var_58 * $_var_59 / 10, 2);
						$_var_60 = ["name" => "配额卡密", "value" => is_Quota_Num($_var_57["value"]) . " 个", "num" => $_var_57["num"] . " 张", "oprice" => $_var_58 . "元", "zk" => $_var_59 . "折", "price" => $_var_60 . "元"];
						return resultJson(1, "获取成功", $_var_60);
						break;
					case "agent":
						$_var_58 = $_var_57["num"] * config("sys.agent_price_" . $_var_57["value"] . "");
						$_var_59 = config("sys.agent_give_z_" . Session::get("user.agent") . "");
						$_var_60 = round($_var_58 * $_var_59 / 10, 2);
						$_var_60 = ["name" => "代理卡密", "value" => is_Agent_Name($_var_57["value"]) . "", "num" => $_var_57["num"] . " 张", "oprice" => $_var_58 . "元", "zk" => $_var_59 . "折", "price" => $_var_60 . "元"];
						return resultJson(1, "获取成功", $_var_60);
						break;
				}
				break;
			case "add":
				$_var_57 = Request::post();
				switch ($_var_57["type"]) {
					case "vip":
					case "quota":
					case "agent":
						try {
							validate(\app\index\validate\Kms::class)->scene("add")->check($_var_57);
						} catch (ValidateException $_var_61) {
							return resultJson(-1, $_var_61->getMessage());
						}
						return Kms::agent_add($_var_57);
						break;
				}
				break;
		}
	}
	public function clearCache()
	{
		if (opcache_reset()) {
			return resultJson(1, "清理缓存成功");
		}
	}
}

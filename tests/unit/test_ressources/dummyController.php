<?

namespace pz\Test\Ressources;

use Dotenv\Util\Regex;
use pz\Controller;
use pz\Routing\Response;
use pz\Routing\Request;
use pz\Enums\Routing\ResponseCode;

class DummyController extends Controller
{
    public function page_index(Request $request): Response
    {
        return new Response(true, ResponseCode::Ok, "index");
    }
    
    public function good_method(Request $request): Response 
    {
        return new Response(true, ResponseCode::Ok, "ok");
    }

    public function bad_method(Request $request): Response 
    {
        return new Response(false, ResponseCode::InternalServerError, "error");
    }
}
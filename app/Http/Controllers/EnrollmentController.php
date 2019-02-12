<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\User;
use App\Models\Course;
use App\Models\Period;
use App\Models\Comment;
use App\Models\Student;
use App\Models\Enrollment;
use App\Models\PreInvoice;
use Illuminate\Http\Request;
use App\Traits\PeriodSelection;
use App\Models\PreInvoiceDetail;


class EnrollmentController extends Controller
{
    use PeriodSelection;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(['permission:enrollments.create']);
    }


    /**
     * Store the newly created enrollment
     */
    public function store(Request $request)
    {
        $course = Course::findOrFail($request->input('course_id'));
        $student = Student::findOrFail($request->input('student_id'));
        $enrollment_id = $student->enroll($course);
        \Alert::success(__('Enrollment successfully created'))->flash();
        return redirect()->to("/enrollment/$enrollment_id");
    }



    /**
     * Show the page for billing the specified enrollment
     */
    public function bill(Enrollment $enrollment)
    {

        // if the current enrollment is not part of the cart, add it
        $enrollment->addToCart();

        // add default other products: enrollment fee + books associated to the course, if any (and if they do not already exist in the cart)
        Cart::add_default_products($enrollment->student_id);

        // the pending products (if any) will also be retrieved from the DB
        $products = Cart::where('user_id', $enrollment->student->user->id)->get();

        // show the cart for the user
        $id = $enrollment->student->user->id;
        return redirect("cart/$id");
    }

    /** this method is temporary. It serves as a shortcut until the invoicing system is in place */
    public function quickBill(Enrollment $enrollment)
    {        
        return view('invoices.create', compact('enrollment'));
    }

    public function quickInvoice(Request $request)
    {
        // get the invoice number and the comment
        $invoice_number = $request->input('invoice_number');
        $comment = $request->input('comment');
        $enrollment = Enrollment::findOrFail($request->input('enrollment_id'));

        if($enrollment->status_id !== 1)
        {
            abort(422, 'Esta matricula no esta pendiente');
        }

        // create a preinvoice with this enrollment
        $preinvoice = new PreInvoice;
        $preinvoice->user_id = $enrollment->student->id;
        $preinvoice->client_name = $enrollment->student->name;
        $preinvoice->client_idnumber =  $enrollment->student->idnumber;
        $preinvoice->client_address = $enrollment->student->address;
        $preinvoice->client_email = $enrollment->student->email;
        $preinvoice->total_price = $enrollment->product->price ?? 0;
        $preinvoice->save();

        // generate a preinvoice product (detail)
        $detail = new PreInvoiceDetail;
        $detail->pre_invoice_id = $preinvoice->id;
        $detail->product_name = $enrollment->product->name ?? 'curso';
        $detail->price = $enrollment->product->price ?? 0;
        $detail->save();

        // change the enrollment status
        $enrollment->status_id = 2;
        $enrollment->save();

        // and status of children enrollments

        if($enrollment->children->count() > 0)
        {
            foreach ($enrollment->children as $child_enrollment)
            {
                $child_enrollment->status_id = 2;
                $child_enrollment->save();
            }
        }

        if($comment !== null) {
            Comment::create([
                'commentable_id' => $preinvoice->id,
                'commentable_type' => Preinvoice::class,
                'body' => $comment,
                'private' => true,
                'author_id' => backpack_user()->id,
            ]);
        }

        if($invoice_number !== null) {
            $preinvoice->invoice_number = $invoice_number;
            $preinvoice->save();
        }

        // link the enrollment and the preinvoice
        $preinvoice->enrollments()->attach($enrollment);


        // return the preinvoice id for redirect
        // todo allow comments on that page.
        return redirect("/enrollment/$enrollment->id");
        //return $preinvoice->id;
    }
}
<?php namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Phphub\Core\CreatorListener;
use App\Models\Topic;
use App\Models\SiteStatus;
use App\Models\Link;
use App\Models\Notification;
use App\Models\Append;
use App\Models\Category;
use App\Models\Banner;
use App\Models\ActiveUser;
use App\Models\HotTopic;
use Phphub\Handler\Exception\ImageUploadException;
use Phphub\Markdown\Markdown;
use Illuminate\Http\Request;
use App\Http\Requests\StoreTopicRequest;
use Auth;
use Flash;
use Image;
use Request as UserRequest;

class TopicsController extends Controller implements CreatorListener
{

    public function __construct()
    {
        $this->middleware('auth', ['except' => ['index', 'show']]);
    }

    public function index(Request $request, Topic $topic)
    {
        $topics = $topic->getTopicsWithFilter($request->get('filter'), 40);
        $links  = Link::allFromCache();
        $banners = Banner::allByPosition();

        $active_users = ActiveUser::fetchAll();
        $hot_topics = HotTopic::fetchAll();

        return view('topics.index', compact('topics', 'links', 'banners', 'active_users', 'hot_topics'));
    }

    public function create(Request $request)
    {
        $category = Category::find($request->input('category_id'));
        $categories = Category::where('id', '!=', config('phphub.blog_category_id'))->get();

        return view('topics.create_edit', compact('categories', 'category'));
    }

    public function store(StoreTopicRequest $request)
    {
        return app('Phphub\Creators\TopicCreator')->create($this, $request->except('_token'));
    }

    public function show($id)
    {
        $topic = Topic::where('id', $id)->with('user', 'lastReplyUser')->firstOrFail();

        if ($topic->user->is_banned == 'yes') {
            // 未登录，或者已登录但是没有管理员权限
            if (!Auth::check() || (Auth::check() && !Auth::user()->may('manage_topics'))) {
                Flash::error('你访问的文章已被屏蔽，有疑问请发邮件：all@estgroupe.com');
                return redirect(route('topics.index'));
            }
            Flash::error('当前文章的作者已被屏蔽，游客与用户将看不到此文章。');
        }

        if (
            config('app.admin_board_cid')
            && $topic->id == config('app.admin_board_cid')
            && (!Auth::check() || !Auth::user()->can('access_board'))
        ) {
            Flash::error('您没有权限访问该文章，有疑问请发邮件：all@estgroupe.com');
            return redirect()->route('topics.index');
        }

        $randomExcellentTopics = $topic->getRandomExcellent();
        $replies = $topic->getRepliesWithLimit(config('phphub.replies_perpage'));
        $categoryTopics = $topic->getSameCategoryTopics();
        $userTopics = $topic->byWhom($topic->user_id)->with('user')->withoutBoardTopics()->recent()->limit(3)->get();
        $votedUsers = $topic->votes()->orderBy('id', 'desc')->with('user')->get()->pluck('user');
        $revisionHistory = $topic->revisionHistory()->orderBy('created_at', 'DESC')->first();
        $topic->increment('view_count', 1);

        $banners  = Banner::allByPosition();

        if ($topic->isArticle()) {

            if (UserRequest::is('topics*')) {
                return redirect()->route('articles.show', [$topic->id]);
            }

            $user = $topic->user;
            $blog = $user->blogs()->first();
            return view('articles.show', compact(
                                'blog', 'user','topic', 'replies', 'categoryTopics',
                                'category', 'banners', 'randomExcellentTopics',
                                'votedUsers', 'userTopics', 'revisionHistory'));
        } else {
            return view('topics.show', compact(
                                'topic', 'replies', 'categoryTopics',
                                'category', 'banners', 'randomExcellentTopics',
                                'votedUsers', 'userTopics', 'revisionHistory'));
        }
    }

    public function edit($id)
    {
        $topic = Topic::findOrFail($id);
        $this->authorize('update', $topic);
        $categories = Category::where('id', '!=', config('phphub.blog_category_id'))->get();
        $category = $topic->category;

        $topic->body = $topic->body_original;

        return view('topics.create_edit', compact('topic', 'categories', 'category'));
    }

    public function append($id, Request $request)
    {
        $topic = Topic::findOrFail($id);
        $this->authorize('append', $topic);

        $markdown = new Markdown;
        $content = $markdown->convertMarkdownToHtml($request->input('content'));

        $append = Append::create(['topic_id' => $topic->id, 'content' => $content]);

        app('Phphub\Notification\Notifier')->newAppendNotify(Auth::user(), $topic, $append);

        return response([
                    'status'  => 200,
                    'message' => lang('Operation succeeded.'),
                    'append'  => $append
                ]);
    }

    public function update($id, StoreTopicRequest $request)
    {
        $topic = Topic::findOrFail($id);
        $this->authorize('update', $topic);

        $data = $request->only('title', 'body', 'category_id');

        $markdown = new Markdown;
        $data['body_original'] = $data['body'];
        $data['body'] = $markdown->convertMarkdownToHtml($data['body']);
        $data['excerpt'] = Topic::makeExcerpt($data['body']);

        $topic->update($data);

        Flash::success(lang('Operation succeeded.'));

        $route = $topic->isArticle() ? 'articles.show' : 'topics.show';

        return redirect()->route($route, $topic->id);
    }

    /**
     * ----------------------------------------
     * User Topic Vote function
     * ----------------------------------------
     */

    public function upvote($id)
    {
        $topic = Topic::find($id);
        app('Phphub\Vote\Voter')->topicUpVote($topic);

        return response(['status' => 200]);
    }

    public function downvote($id)
    {
        $topic = Topic::find($id);
        app('Phphub\Vote\Voter')->topicDownVote($topic);

        return response(['status' => 200]);
    }

    /**
     * ----------------------------------------
     * Admin Topic Management
     * ----------------------------------------
     */

    public function recommend($id)
    {
        $topic = Topic::findOrFail($id);
        $this->authorize('recommend', $topic);
        $topic->is_excellent = $topic->is_excellent == 'yes' ? 'no' : 'yes';
        $topic->save();
        Notification::notify('topic_mark_excellent', Auth::user(), $topic->user, $topic);

        return response(['status' => 200, 'message' => lang('Operation succeeded.')]);
    }

    public function pin($id)
    {
        $topic = Topic::findOrFail($id);
        $this->authorize('pin', $topic);

        $topic->order = $topic->order > 0 ? 0 : 999;
        $topic->save();

        return response(['status' => 200, 'message' => lang('Operation succeeded.')]);
    }

    public function sink($id)
    {
        $topic = Topic::findOrFail($id);
        $this->authorize('sink', $topic);

        $topic->order = $topic->order >= 0 ? -1 : 0;
        $topic->save();

        return response(['status' => 200, 'message' => lang('Operation succeeded.')]);
    }

    public function destroy($id)
    {
        $topic = Topic::findOrFail($id);
        $this->authorize('delete', $topic);
        $topic->delete();
        Flash::success(lang('Operation succeeded.'));

        if ($topic->isArticle()) {
            Auth::user()->decrement('article_count', 1);
        } else {
            Auth::user()->decrement('topic_count', 1);
        }

        return redirect()->route('topics.index');
    }

    public function uploadImage(Request $request)
    {
        if ($file = $request->file('file')) {
            try {
                $upload_status = app('Phphub\Handler\ImageUploadHandler')->uploadImage($file);
            } catch (ImageUploadException $exception) {
                return ['error' => $exception->getMessage()];
            }
            $data['filename'] = $upload_status['filename'];

            SiteStatus::newImage();
        } else {
            $data['error'] = 'Error while uploading file';
        }
        return $data;
    }

    /**
     * ----------------------------------------
     * CreatorListener Delegate
     * ----------------------------------------
     */

    public function creatorFailed($errors)
    {
        return redirect('/');
    }

    public function creatorSucceed($topic)
    {
        Flash::success(lang('Operation succeeded.'));
        return redirect(route('topics.show', array($topic->id)));
    }
}

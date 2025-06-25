 
<div class="container mt-5">
    <h2>Upload File to Cloudflare R2</h2>

    {{-- Success Message --}}
    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}<br>
            <strong>File Path:</strong> {{ session('path') }}<br>
            <strong>URL:</strong> <a href="{{ session('url') }}" target="_blank">{{ session('url') }}</a>
        </div>
    @endif

    {{-- Error Message --}}
    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Upload failed:</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Upload Form --}}
    <form method="POST" action="{{ route('upload.file') }}" enctype="multipart/form-data">
        @csrf

        <div class="mb-3">
            <label for="file" class="form-label">Choose File</label>
            <input type="file" class="form-control" name="file" required>
        </div>

        <button type="submit" class="btn btn-primary">Upload</button>
    </form>
</div>
 

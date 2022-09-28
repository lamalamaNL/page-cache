# Laravel Page Cache

Original package at

### URL rewriting

This package is designed to work together with the statamoc

- **For nginx:**

    Update your `location` block's `try_files` directive to include a check in the `static-pagecache` directory:

    ```nginxconf
    try_files $uri "/static/$host${uri}_${cache_query_string}.html" "/static-pagecache/$host${uri}_${cache_query_string}.html" /index.php?$query_string;
    ```



- **Debugging nginx:**

  Update your `location` block's `try_files` directive to include a check in the `page-cache` directory:

    ```nginxconf
    # Testing
        #add_header Content-Type text/plain;
        #return 200 "/static-pagecache${uri}_${cache_query_string}.html";
        #return 200 "/static/$host${uri}_${cache_query_string}.html";
        #return 200 "/static-pagecache/$host${uri}_${cache_query_string}.html";
    ```

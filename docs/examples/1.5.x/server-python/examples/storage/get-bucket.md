from appwrite.client import Client
from appwrite.services.storage import Storage

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('<YOUR_PROJECT_ID>') # Your project ID
client.set_key('<YOUR_API_KEY>') # Your secret API key

storage = Storage(client)

result = storage.get_bucket(
    bucket_id = '<BUCKET_ID>'
)

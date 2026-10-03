import { Head, Link, usePage } from '@inertiajs/react';
import { Gauge, ShieldCheck, Split } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { index as imports } from '@/routes/imports';

const features = [
    {
        icon: Split,
        title: 'Streams, never loads',
        body: 'Files are read as a stream and split on record boundaries, so memory stays flat from 10 rows to 10 million.',
    },
    {
        icon: Gauge,
        title: 'Parallel and resumable',
        body: 'Chunks run as a queued batch across workers, insert in bulk and resume from their last committed batch after a crash.',
    },
    {
        icon: ShieldCheck,
        title: 'Bad rows don’t stop it',
        body: 'Every invalid row is reported with its line, column and reason, and the error report is safe to open in a spreadsheet.',
    },
];

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between p-6">
                    <span className="flex items-center gap-2 font-semibold">
                        <AppLogoIcon className="size-6 fill-current" />
                        {name}
                    </span>
                    <nav className="flex gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={imports()}>Your imports</Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={register()}>Register</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-12 p-6">
                    <div className="max-w-2xl space-y-4">
                        <h1 className="text-4xl font-semibold tracking-tight">
                            Import millions of CSV rows, reliably.
                        </h1>
                        <p className="text-lg text-muted-foreground">
                            Upload a file, watch it validate and load in
                            parallel, and download a report of anything that
                            didn’t fit.
                        </p>
                    </div>

                    <div className="grid gap-6 md:grid-cols-3">
                        {features.map(({ icon: Icon, title, body }) => (
                            <div key={title} className="rounded-xl border p-5">
                                <Icon className="mb-3 size-5 text-muted-foreground" />
                                <h2 className="mb-1 font-medium">{title}</h2>
                                <p className="text-sm text-muted-foreground">
                                    {body}
                                </p>
                            </div>
                        ))}
                    </div>
                </main>
            </div>
        </>
    );
}
